using System.Globalization;
using System.Text.Json;

namespace Monitor.Panel.Monitoring;

/// <summary>
/// Converte traces OpenTelemetry (OTLP/JSON, um ExportTraceServiceRequest por trace) no modelo do painel.
/// Espera o que o agente grava: um span SERVER (a requisição) e spans CLIENT filhos (SQL, HTTP, Redis, sessão),
/// com os atributos monitor.* além dos semânticos do OpenTelemetry.
/// </summary>
public static class Otlp
{
    public static IEnumerable<RequestRecord> ToRequests(JsonElement trace, string fallbackServer)
    {
        if (!trace.TryGetProperty("resourceSpans", out var resourceSpans)) yield break;

        foreach (var rs in resourceSpans.EnumerateArray())
        {
            var resource = Attributes(rs.TryGetProperty("resource", out var r) ? r : default);
            var server = Str(resource, "host.name") ?? fallbackServer;

            var spans = rs.GetProperty("scopeSpans").EnumerateArray()
                .SelectMany(s => s.GetProperty("spans").EnumerateArray())
                .ToList();
            var root = spans.FirstOrDefault(s => s.TryGetProperty("kind", out var k) && k.GetInt32() == 2);
            if (root.ValueKind != JsonValueKind.Object) continue;

            var a = Attributes(root);
            var start = Nanos(root, "startTimeUnixNano");
            var end = Nanos(root, "endTimeUnixNano");
            var rootId = root.GetProperty("spanId").GetString()!;

            var children = spans
                .Where(s => s.TryGetProperty("parentSpanId", out var p) && p.GetString() == rootId)
                .Select(s => ToSpan(s, start))
                .OrderBy(s => s.At)
                .ToList();

            string? error = null;
            if (root.TryGetProperty("status", out var st) && st.TryGetProperty("code", out var code) && code.GetInt32() == 2)
                error = st.TryGetProperty("message", out var m) ? m.GetString() : "erro";

            var path = Str(a, "url.path");
            yield return new RequestRecord(
                Id: rootId,
                Ts: DateTimeOffset.FromUnixTimeMilliseconds((long)(start / 1_000_000)).ToLocalTime(),
                Method: Str(a, "http.request.method") ?? "GET",
                Route: Str(a, "http.route") ?? path ?? "/",
                Path: path,
                Query: Str(a, "url.query"),
                Status: (int)(Num(a, "http.response.status_code") ?? 0),
                Ms: (end - start) / 1_000_000,
                CpuMs: Num(a, "monitor.cpu_ms") ?? 0,
                CpuUserMs: Num(a, "monitor.cpu_user_ms") ?? 0,
                CpuSysMs: Num(a, "monitor.cpu_sys_ms") ?? 0,
                Mem: (long)(Num(a, "monitor.memory.reserved") ?? 0),
                MemPeak: (long)(Num(a, "monitor.memory.peak") ?? 0),
                ExtMs: Num(a, "monitor.ext_ms") ?? children.Sum(c => c.Ms),
                ExtCalls: (int)(Num(a, "monitor.ext_calls") ?? children.Count),
                Detailed: Bool(a, "monitor.detailed") ?? true,
                Spans: children,
                SpansDropped: (int)(Num(a, "monitor.spans_dropped") ?? 0),
                Ip: Str(a, "client.address"),
                Ua: Str(a, "user_agent.original"),
                Referer: Str(a, "http.request.header.referer"),
                Error: error,
                Server: server,
                TraceId: root.GetProperty("traceId").GetString() ?? "",
                ParentSpanId: root.TryGetProperty("parentSpanId", out var ps) ? ps.GetString() : null);
        }
    }

    private static Span ToSpan(JsonElement s, double rootStart)
    {
        var a = Attributes(s);
        var start = Nanos(s, "startTimeUnixNano");
        var end = Nanos(s, "endTimeUnixNano");
        var ok = !(s.TryGetProperty("status", out var st) && st.TryGetProperty("code", out var c) && c.GetInt32() == 2);
        string? error = ok ? null : st.TryGetProperty("message", out var m) ? m.GetString() : "erro";

        SpanDetail? detail = null;
        if (Str(a, "monitor.type") == "http")
        {
            detail = new SpanDetail(
                Status: (int?)Num(a, "http.response.status_code"),
                Dns: Num(a, "monitor.http.dns_ms"),
                Connect: Num(a, "monitor.http.connect_ms"),
                Tls: Num(a, "monitor.http.tls_ms"),
                Ttfb: Num(a, "monitor.http.ttfb_ms"),
                Total: Num(a, "monitor.http.total_ms"),
                Bytes: (long?)Num(a, "monitor.http.bytes"));
        }

        return new Span(
            Type: Str(a, "monitor.type") ?? "outro",
            Target: Str(a, "monitor.target") ?? Str(a, "server.address") ?? "?",
            Op: Str(a, "monitor.op") ?? s.GetProperty("name").GetString() ?? "",
            At: (start - rootStart) / 1_000_000,
            Ms: (end - start) / 1_000_000,
            Ok: ok,
            Error: error,
            Detail: detail,
            Id: s.GetProperty("spanId").GetString());
    }

    private static Dictionary<string, JsonElement> Attributes(JsonElement owner)
    {
        var map = new Dictionary<string, JsonElement>();
        if (owner.ValueKind == JsonValueKind.Object && owner.TryGetProperty("attributes", out var attrs))
        {
            foreach (var kv in attrs.EnumerateArray())
            {
                var value = kv.GetProperty("value");
                foreach (var v in value.EnumerateObject()) { map[kv.GetProperty("key").GetString()!] = v.Value; break; }
            }
        }
        return map;
    }

    private static string? Str(Dictionary<string, JsonElement> a, string key) =>
        a.TryGetValue(key, out var v) ? v.ValueKind == JsonValueKind.String ? v.GetString() : v.ToString() : null;

    // intValue vem como string no OTLP/JSON; doubleValue como número.
    private static double? Num(Dictionary<string, JsonElement> a, string key) =>
        !a.TryGetValue(key, out var v) ? null
        : v.ValueKind == JsonValueKind.Number ? v.GetDouble()
        : double.TryParse(v.GetString(), NumberStyles.Float, CultureInfo.InvariantCulture, out var d) ? d : null;

    private static bool? Bool(Dictionary<string, JsonElement> a, string key) =>
        a.TryGetValue(key, out var v) && v.ValueKind is JsonValueKind.True or JsonValueKind.False ? v.GetBoolean() : null;

    private static double Nanos(JsonElement span, string prop)
    {
        var v = span.GetProperty(prop);
        return v.ValueKind == JsonValueKind.Number
            ? v.GetDouble()
            : double.Parse(v.GetString()!, NumberStyles.Float, CultureInfo.InvariantCulture);
    }
}
