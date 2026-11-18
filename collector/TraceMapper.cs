using System.Globalization;
using System.Text.Encodings.Web;
using System.Text.Json;

namespace Monitor.Collector;

/// <summary>
/// Um lote pronto para o ClickHouse: linhas JSONEachRow de "requests" e de "calls".
/// </summary>
public sealed class Batch : IDisposable
{
    private static readonly JsonWriterOptions WriterOptions = new()
    {
        Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
        SkipValidation = true, // várias linhas (objetos) no mesmo writer
    };

    public MemoryStream Requests { get; } = new();
    public MemoryStream Calls { get; } = new();
    public int RequestCount { get; internal set; }
    public int CallCount { get; internal set; }
    public int BadLines { get; internal set; }
    /// <summary>Sites, servidores e faixa de horário (unix ns) das requisições do lote: usados para conferir no ClickHouse.</summary>
    public HashSet<string> Sites { get; } = [];
    public HashSet<string> Servers { get; } = [];
    public long MinStart { get; private set; } = long.MaxValue;
    public long MaxStart { get; private set; } = long.MinValue;

    internal void Track(string site, string server, long start)
    {
        Sites.Add(site);
        Servers.Add(server);
        MinStart = Math.Min(MinStart, start);
        MaxStart = Math.Max(MaxStart, start);
    }

    internal Utf8JsonWriter RequestWriter { get; }
    internal Utf8JsonWriter CallWriter { get; }

    public Batch()
    {
        RequestWriter = new Utf8JsonWriter(Requests, WriterOptions);
        CallWriter = new Utf8JsonWriter(Calls, WriterOptions);
    }

    public void Flush()
    {
        RequestWriter.Flush();
        CallWriter.Flush();
    }

    /// <summary>Posição do lote antes de uma linha (cada linha gravada termina com Flush, então o stream está em dia).</summary>
    internal (long Requests, long Calls, int RequestCount, int CallCount) Mark() =>
        (Requests.Length, Calls.Length, RequestCount, CallCount);

    /// <summary>Desfaz o que uma linha escreveu pela metade (objeto JSON incompleto não pode ir ao ClickHouse).</summary>
    internal void Rollback((long Requests, long Calls, int RequestCount, int CallCount) mark)
    {
        RequestWriter.Reset();
        CallWriter.Reset();
        Requests.SetLength(mark.Requests);
        Requests.Position = mark.Requests;
        Calls.SetLength(mark.Calls);
        Calls.Position = mark.Calls;
        RequestCount = mark.RequestCount;
        CallCount = mark.CallCount;
    }

    public void Dispose()
    {
        RequestWriter.Dispose();
        CallWriter.Dispose();
        Requests.Dispose();
        Calls.Dispose();
    }
}

/// <summary>
/// Converte uma linha gravada pelo agente (OTLP/JSON, um ExportTraceServiceRequest por requisição) em linhas das
/// tabelas requests (span SERVER) e calls (demais spans, com a rota da requisição repetida).
/// </summary>
public static class TraceMapper
{
    private const int KindServer = 2;
    private const int StatusError = 2;

    /// <summary>
    /// Acrescenta a linha ao lote. Devolve false se a linha não for um trace válido.
    /// Uma linha com formato inesperado nunca pode travar o arquivo: o que ela já escreveu no lote é desfeito e ela
    /// conta como inválida.
    /// </summary>
    public static bool Append(Batch batch, ReadOnlySpan<byte> line, string site, string fallbackServer)
    {
        var mark = batch.Mark();
        try
        {
            return AppendUnsafe(batch, line, site, fallbackServer);
        }
        catch (Exception ex) when (ex is not OutOfMemoryException)
        {
            batch.Rollback(mark);
            batch.BadLines++;
            return false;
        }
    }

    private static bool AppendUnsafe(Batch batch, ReadOnlySpan<byte> line, string site, string fallbackServer)
    {
        JsonDocument doc;
        try { doc = JsonDocument.Parse(line.ToArray()); }
        catch (JsonException) { batch.BadLines++; return false; }

        using (doc)
        {
            if (!doc.RootElement.TryGetProperty("resourceSpans", out var resourceSpans) ||
                resourceSpans.ValueKind != JsonValueKind.Array)
            {
                batch.BadLines++;
                return false;
            }

            foreach (var rs in resourceSpans.EnumerateArray())
            {
                var resource = Attributes(rs.TryGetProperty("resource", out var r) ? r : default);
                var server = Get(resource, "host.name") ?? fallbackServer;
                var service = Get(resource, "service.name") ?? site;

                var spans = new List<JsonElement>();
                if (rs.TryGetProperty("scopeSpans", out var scopes))
                    foreach (var scope in scopes.EnumerateArray())
                        if (scope.TryGetProperty("spans", out var ss))
                            spans.AddRange(ss.EnumerateArray());

                var root = spans.FirstOrDefault(s => Int(s, "kind") == KindServer);
                string method = "", route = "";
                long rootStart = 0;
                if (root.ValueKind == JsonValueKind.Object)
                {
                    var a = Attributes(root);
                    rootStart = Nanos(root, "startTimeUnixNano");
                    method = Get(a, "http.request.method") ?? "GET";
                    route = Get(a, "http.route") ?? Get(a, "url.path") ?? "/";
                    WriteRequest(batch, root, a, resource, service, server, method, route);
                }

                foreach (var s in spans)
                {
                    if (Int(s, "kind") == KindServer) continue;
                    WriteCall(batch, s, service, server, method, route, rootStart);
                }
            }
        }
        return true;
    }

    private static void WriteRequest(Batch b, JsonElement span, Dictionary<string, JsonElement> a,
        Dictionary<string, JsonElement> resource, string site, string server, string method, string route)
    {
        var start = Nanos(span, "startTimeUnixNano");
        var end = Nanos(span, "endTimeUnixNano");
        var (isError, error) = Status(span);
        var status = (int)(Num(a, "http.response.status_code") ?? 0);

        var w = b.RequestWriter;
        w.WriteStartObject();
        w.WriteString("Timestamp", Timestamp(start));
        w.WriteString("Site", site);
        w.WriteString("Server", server);
        w.WriteString("TraceId", Str(span, "traceId"));
        w.WriteString("SpanId", Str(span, "spanId"));
        w.WriteString("ParentSpanId", Str(span, "parentSpanId"));
        w.WriteString("Method", method);
        w.WriteString("Route", route);
        w.WriteString("Path", Get(a, "url.path") ?? "");
        w.WriteString("Query", Get(a, "url.query") ?? "");
        w.WriteNumber("Status", Math.Clamp(status, 0, ushort.MaxValue));
        w.WriteNumber("DurationMs", Math.Max(0, end - start) / 1_000_000.0);
        w.WriteNumber("CpuMs", Num(a, "monitor.cpu_ms") ?? 0);
        w.WriteNumber("CpuUserMs", Num(a, "monitor.cpu_user_ms") ?? 0);
        w.WriteNumber("CpuSysMs", Num(a, "monitor.cpu_sys_ms") ?? 0);
        w.WriteNumber("MemPeak", (ulong)Math.Max(0, Num(a, "monitor.memory.peak") ?? 0));
        w.WriteNumber("MemReserved", (ulong)Math.Max(0, Num(a, "monitor.memory.reserved") ?? 0));
        w.WriteNumber("ExtMs", Num(a, "monitor.ext_ms") ?? 0);
        w.WriteNumber("ExtCalls", (uint)Math.Max(0, Num(a, "monitor.ext_calls") ?? 0));
        w.WriteBoolean("Detailed", Bool(a, "monitor.detailed") ?? true);
        w.WriteNumber("SpansDropped", (uint)Math.Max(0, Num(a, "monitor.spans_dropped") ?? 0));
        w.WriteBoolean("IsError", isError || status >= 500);
        w.WriteString("Error", error ?? "");
        w.WriteString("ClientIp", Get(a, "client.address") ?? "");
        w.WriteString("UserAgent", Get(a, "user_agent.original") ?? "");
        w.WriteString("Referer", Get(a, "http.request.header.referer") ?? "");
        WriteMap(w, "Attributes", a);
        WriteMap(w, "ResourceAttributes", resource);
        w.WriteEndObject();
        w.Flush();
        w.Reset(); // próxima linha começa um JSON novo (sem vírgula)
        b.Requests.WriteByte((byte)'\n');
        b.RequestCount++;
        b.Track(site, server, start);
    }

    private static void WriteCall(Batch b, JsonElement span, string site, string server, string method, string route, long rootStart)
    {
        var a = Attributes(span);
        var start = Nanos(span, "startTimeUnixNano");
        var end = Nanos(span, "endTimeUnixNano");
        var (isError, error) = Status(span);
        var name = Str(span, "name");

        var w = b.CallWriter;
        w.WriteStartObject();
        w.WriteString("Timestamp", Timestamp(start));
        w.WriteString("Site", site);
        w.WriteString("Server", server);
        w.WriteString("TraceId", Str(span, "traceId"));
        w.WriteString("SpanId", Str(span, "spanId"));
        w.WriteString("ParentSpanId", Str(span, "parentSpanId"));
        w.WriteString("Method", method);
        w.WriteString("Route", route);
        w.WriteString("Type", Get(a, "monitor.type") ?? "outro");
        w.WriteString("Target", Get(a, "monitor.target") ?? Get(a, "server.address") ?? "?");
        w.WriteString("Op", Get(a, "monitor.op") ?? name);
        w.WriteString("Name", name);
        w.WriteNumber("OffsetMs", rootStart == 0 ? 0 : (start - rootStart) / 1_000_000.0);
        w.WriteNumber("DurationMs", Math.Max(0, end - start) / 1_000_000.0);
        w.WriteBoolean("Ok", !isError);
        w.WriteString("Error", error ?? "");
        w.WriteNumber("HttpStatus", (int)Math.Clamp(Num(a, "http.response.status_code") ?? 0, 0, ushort.MaxValue));
        WriteNullable(w, "DnsMs", Num(a, "monitor.http.dns_ms"));
        WriteNullable(w, "ConnectMs", Num(a, "monitor.http.connect_ms"));
        WriteNullable(w, "TlsMs", Num(a, "monitor.http.tls_ms"));
        WriteNullable(w, "TtfbMs", Num(a, "monitor.http.ttfb_ms"));
        if (Num(a, "monitor.http.bytes") is { } bytes) w.WriteNumber("Bytes", (ulong)Math.Max(0, bytes));
        else w.WriteNull("Bytes");
        WriteMap(w, "Attributes", a);
        w.WriteEndObject();
        w.Flush();
        w.Reset(); // próxima linha começa um JSON novo (sem vírgula)
        b.Calls.WriteByte((byte)'\n');
        b.CallCount++;
    }

    private static (bool IsError, string? Message) Status(JsonElement span)
    {
        if (span.TryGetProperty("status", out var st) && st.TryGetProperty("code", out var c) &&
            c.ValueKind == JsonValueKind.Number && c.GetInt32() == StatusError)
            return (true, st.TryGetProperty("message", out var m) ? m.GetString() ?? "erro" : "erro");
        return (false, null);
    }

    private static void WriteNullable(Utf8JsonWriter w, string name, double? value)
    {
        if (value is { } v) w.WriteNumber(name, v);
        else w.WriteNull(name);
    }

    private static void WriteMap(Utf8JsonWriter w, string name, Dictionary<string, JsonElement> map)
    {
        w.WriteStartObject(name);
        foreach (var (k, v) in map) w.WriteString(k, AsString(v));
        w.WriteEndObject();
    }

    /// <summary>"2026-11-13 12:05:06.199756" em UTC (DateTime64(6, 'UTC')).</summary>
    private static string Timestamp(long unixNanos) =>
        DateTime.UnixEpoch.AddTicks(unixNanos / 100).ToString("yyyy-MM-dd HH:mm:ss.ffffff", CultureInfo.InvariantCulture);

    /// <summary>Atributos OTLP (key + value {stringValue|intValue|doubleValue|boolValue|...}) num dicionário.</summary>
    private static Dictionary<string, JsonElement> Attributes(JsonElement owner)
    {
        var map = new Dictionary<string, JsonElement>();
        if (owner.ValueKind == JsonValueKind.Object && owner.TryGetProperty("attributes", out var attrs) &&
            attrs.ValueKind == JsonValueKind.Array)
        {
            foreach (var kv in attrs.EnumerateArray())
            {
                if (!kv.TryGetProperty("key", out var key) || !kv.TryGetProperty("value", out var value) ||
                    value.ValueKind != JsonValueKind.Object) continue;
                foreach (var v in value.EnumerateObject()) { map[key.GetString()!] = v.Value; break; }
            }
        }
        return map;
    }

    private static string AsString(JsonElement v) => v.ValueKind switch
    {
        JsonValueKind.String => v.GetString() ?? "",
        JsonValueKind.True => "true",
        JsonValueKind.False => "false",
        JsonValueKind.Null => "",
        _ => v.GetRawText(),
    };

    private static string? Get(Dictionary<string, JsonElement> a, string key) =>
        a.TryGetValue(key, out var v) ? AsString(v) : null;

    // intValue vem como string no OTLP/JSON; doubleValue como número.
    private static double? Num(Dictionary<string, JsonElement> a, string key) =>
        !a.TryGetValue(key, out var v) ? null
        : v.ValueKind == JsonValueKind.Number ? v.GetDouble()
        : v.ValueKind == JsonValueKind.String &&
          double.TryParse(v.GetString(), NumberStyles.Float, CultureInfo.InvariantCulture, out var d) ? d : null;

    private static bool? Bool(Dictionary<string, JsonElement> a, string key) =>
        a.TryGetValue(key, out var v) && v.ValueKind is JsonValueKind.True or JsonValueKind.False ? v.GetBoolean() : null;

    private static string Str(JsonElement e, string prop) =>
        e.TryGetProperty(prop, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : "";

    private static int Int(JsonElement e, string prop) =>
        e.TryGetProperty(prop, out var v) && v.ValueKind == JsonValueKind.Number ? v.GetInt32() : 0;

    private static long Nanos(JsonElement span, string prop)
    {
        if (!span.TryGetProperty(prop, out var v)) return 0;
        if (v.ValueKind == JsonValueKind.Number) return v.TryGetInt64(out var n) ? n : (long)v.GetDouble();
        return long.TryParse(v.GetString(), NumberStyles.Integer, CultureInfo.InvariantCulture, out var s) ? s : 0;
    }
}
