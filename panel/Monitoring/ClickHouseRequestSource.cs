using System.Globalization;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Options;

namespace Monitor.Panel.Monitoring;

public sealed class ClickHouseOptions
{
    /// <summary>Interface HTTP do ClickHouse, ex.: http://clickhouse:8123. Vazio: o painel lê dos servidores (memória).</summary>
    public string? Url { get; set; }
    public string Database { get; set; } = "monitor";
    public string User { get; set; } = "default";
    /// <summary>Também pode vir da variável de ambiente MONITOR_CLICKHOUSE_PASSWORD.</summary>
    public string Password { get; set; } = "";
    public int TimeoutSeconds { get; set; } = 15;
    public bool IgnoreTlsErrors { get; set; }
    /// <summary>Período padrão das estatísticas quando a tela não manda ?minutes=.</summary>
    public int DefaultWindowMinutes { get; set; } = 60;
    /// <summary>Maior período aceito (protege o ClickHouse de consultas de meses).</summary>
    public int MaxWindowMinutes { get; set; } = 30 * 24 * 60;
}

public sealed class ClickHouseQueryException(string message) : Exception(message);

/// <summary>
/// Lê as tabelas gravadas pelo coletor (requests, calls) e devolve o mesmo modelo do painel em memória.
/// Todos os valores vão como parâmetros ({nome:Tipo} + param_nome), nunca concatenados no SQL.
/// </summary>
public sealed class ClickHouseRequestSource(HttpClient http, IOptions<MonitorOptions> options) : IRequestSource
{
    public string Name => "clickhouse";

    private ClickHouseOptions Opt => options.Value.ClickHouse;
    private string Db => "`" + Opt.Database.Replace("`", "") + "`";

    private const string Window = "Site = {site:String} AND Timestamp >= now64(6) - toIntervalSecond({secs:UInt32})";

    public async Task<IReadOnlyList<RouteStats>> RoutesAsync(TargetOptions t, TimeSpan window, CancellationToken ct)
    {
        var rows = await QueryAsync($"""
            SELECT Method, Route, count() AS Hits,
                   countIf(Status < 400) AS Ok, countIf(Status >= 400 AND Status < 500) AS ClientErrors, countIf(Status >= 500) AS ServerErrors,
                   avg(DurationMs) AS AvgMs, quantile(0.95)(DurationMs) AS P95Ms, max(DurationMs) AS MaxMs,
                   avg(CpuMs) AS AvgCpuMs, max(CpuMs) AS MaxCpuMs, avg(MemPeak) AS AvgMemPeak, max(MemPeak) AS MaxMemPeak,
                   avg(ExtMs) AS AvgExtMs, avg(ExtCalls) AS AvgExtCalls,
                   toUnixTimestamp64Milli(max(Timestamp)) AS LastSeen, argMax(Status, Timestamp) AS LastStatus,
                   argMaxIf(Error, Timestamp, Error != '') AS LastError
            FROM {Db}.requests WHERE {Window}
            GROUP BY Method, Route ORDER BY Hits DESC LIMIT 2000
            """, Params(t, window), ct);
        return rows.Select(r => new RouteStats(
            r.S("Method"), r.S("Route"), r.I("Hits"), r.I("Ok"), r.I("ClientErrors"), r.I("ServerErrors"),
            r.D("AvgMs"), r.D("P95Ms"), r.D("MaxMs"), r.D("AvgCpuMs"), r.D("MaxCpuMs"), r.D("AvgMemPeak"), r.L("MaxMemPeak"),
            r.D("AvgExtMs"), r.D("AvgExtCalls"), r.T("LastSeen"), r.I("LastStatus"), r.N("LastError"))).ToList();
    }

    public async Task<IReadOnlyList<RouteChildStats>> RouteChildrenAsync(TargetOptions t, string method, string route, TimeSpan window, CancellationToken ct)
    {
        var p = Params(t, window);
        p["method"] = method;
        p["route"] = route;
        var totals = await QueryAsync($"""
            SELECT count() AS Hits, sum(DurationMs) AS TotalMs FROM {Db}.requests
            WHERE {Window} AND Method = {"{method:String}"} AND Route = {"{route:String}"}
            """, p, ct);
        var hits = totals.Count > 0 ? totals[0].I("Hits") : 0;
        var routeTotal = totals.Count > 0 ? totals[0].D("TotalMs") : 0;
        if (hits == 0) return [];

        var rows = await QueryAsync($"""
            SELECT Type, Target, Op, count() AS Calls, uniqExact(TraceId, ParentSpanId) AS Requests, countIf(NOT Ok) AS Errors,
                   avg(DurationMs) AS AvgMs, max(DurationMs) AS MaxMs, sum(DurationMs) AS TotalMs,
                   argMaxIf(Error, Timestamp, Error != '') AS LastError
            FROM {Db}.calls
            WHERE {Window} AND Method = {"{method:String}"} AND Route = {"{route:String}"}
            GROUP BY Type, Target, Op ORDER BY TotalMs DESC LIMIT 300
            """, p, ct);
        return rows.Select(r => new RouteChildStats(
            r.S("Type"), r.S("Target"), r.S("Op"), r.I("Calls"), r.I("Requests"), (double)r.I("Calls") / hits, r.I("Errors"),
            r.D("AvgMs"), r.D("MaxMs"), r.D("TotalMs"), routeTotal > 0 ? r.D("TotalMs") / routeTotal : 0, r.N("LastError"))).ToList();
    }

    public async Task<IReadOnlyList<DependencyStats>> DependenciesAsync(TargetOptions t, TimeSpan window, CancellationToken ct)
    {
        var p = Params(t, window);
        var deps = QueryAsync($"""
            SELECT Type, Target, count() AS Calls, countIf(NOT Ok) AS Errors,
                   avg(DurationMs) AS AvgMs, quantile(0.95)(DurationMs) AS P95Ms, max(DurationMs) AS MaxMs, avg(TtfbMs) AS AvgTtfbMs,
                   toUnixTimestamp64Milli(max(Timestamp)) AS LastSeen, argMaxIf(Error, Timestamp, Error != '') AS LastError,
                   topK(5)(concat(Method, ' ', Route)) AS Routes
            FROM {Db}.calls WHERE {Window}
            GROUP BY Type, Target ORDER BY AvgMs * Calls DESC LIMIT 200
            """, p, ct);
        var ops = QueryAsync($"""
            SELECT Type, Target, Op, count() AS Calls, countIf(NOT Ok) AS Errors, avg(DurationMs) AS AvgMs, max(DurationMs) AS MaxMs,
                   sum(DurationMs) AS TotalMs
            FROM {Db}.calls WHERE {Window}
            GROUP BY Type, Target, Op ORDER BY Type, Target, TotalMs DESC LIMIT 5 BY Type, Target
            """, p, ct);
        await Task.WhenAll(deps, ops);

        var byDep = (await ops).GroupBy(r => (r.S("Type"), r.S("Target")))
            .ToDictionary(g => g.Key, g => (IReadOnlyList<OperationStats>)g
                .Select(r => new OperationStats(r.S("Op"), r.I("Calls"), r.I("Errors"), r.D("AvgMs"), r.D("MaxMs"))).ToList());
        return (await deps).Select(r => new DependencyStats(
            r.S("Type"), r.S("Target"), r.I("Calls"), r.I("Errors"), r.D("AvgMs"), r.D("P95Ms"), r.D("MaxMs"), r.ND("AvgTtfbMs"),
            r.T("LastSeen"), r.N("LastError"), byDep.GetValueOrDefault((r.S("Type"), r.S("Target"))) ?? [],
            r.Strings("Routes"))).ToList();
    }

    public async Task<IReadOnlyList<ServerStats>> ServersAsync(TargetOptions t, TimeSpan window, CancellationToken ct)
    {
        var rows = await QueryAsync($"""
            SELECT Server, count() AS Hits, countIf(Status >= 500) AS ServerErrors, avg(DurationMs) AS AvgMs,
                   quantile(0.95)(DurationMs) AS P95Ms, avg(CpuMs) AS AvgCpuMs, avg(MemPeak) AS AvgMemPeak,
                   toUnixTimestamp64Milli(max(Timestamp)) AS LastSeen
            FROM {Db}.requests WHERE {Window}
            GROUP BY Server ORDER BY Server
            """, Params(t, window), ct);
        return rows.Select(r => new ServerStats(r.S("Server"), r.I("Hits"), r.I("ServerErrors"), r.D("AvgMs"), r.D("P95Ms"),
            r.D("AvgCpuMs"), r.D("AvgMemPeak"), r.T("LastSeen"))).ToList();
    }

    public async Task<IReadOnlyList<RequestRecord>> RecentAsync(TargetOptions t, RequestFilter f, TimeSpan window, CancellationToken ct)
    {
        var p = Params(t, window);
        var where = new StringBuilder("Site = {site:String}");
        // Um trace é procurado em todo o histórico; o resto, dentro do período.
        if (f.Trace is null) where.Append(" AND Timestamp >= now64(6) - toIntervalSecond({secs:UInt32})");
        if (f.Method is not null) { where.Append(" AND Method = {method:String}"); p["method"] = f.Method; }
        if (f.Route is not null) { where.Append(" AND Route = {route:String}"); p["route"] = f.Route; }
        if (f.Server is not null) { where.Append(" AND Server = {server:String}"); p["server"] = f.Server; }
        if (f.Trace is not null) { where.Append(" AND TraceId = {trace:String}"); p["trace"] = f.Trace; }
        p["limit"] = f.Limit.ToString(CultureInfo.InvariantCulture);

        var rows = await QueryAsync($"""
            SELECT SpanId, toUnixTimestamp64Micro(Timestamp) AS TsUs, Method, Route, Path, Query, Status, DurationMs,
                   CpuMs, CpuUserMs, CpuSysMs, MemReserved, MemPeak, ExtMs, ExtCalls, Detailed, SpansDropped,
                   ClientIp, UserAgent, Referer, Error, Server, TraceId, ParentSpanId
            FROM {Db}.requests WHERE {where}
            ORDER BY Timestamp DESC LIMIT {"{limit:UInt32}"}
            """, p, ct);
        if (rows.Count == 0) return [];

        // Chamadas filhas só das requisições detalhadas desta página.
        var detailed = rows.Where(r => r.B("Detailed") && r.I("ExtCalls") + r.I("SpansDropped") > 0).ToList();
        var spans = new Dictionary<string, List<Span>>();
        if (detailed.Count > 0)
        {
            var minUs = detailed.Min(r => r.L("TsUs"));
            var maxUs = detailed.Max(r => r.L("TsUs") + (long)(r.D("DurationMs") * 1000));
            var sp = new Dictionary<string, string>
            {
                ["site"] = Site(t),
                ["from"] = minUs.ToString(CultureInfo.InvariantCulture),
                ["to"] = maxUs.ToString(CultureInfo.InvariantCulture),
                ["traces"] = ArrayLiteral(detailed.Select(r => r.S("TraceId")).Distinct()),
                ["parents"] = ArrayLiteral(detailed.Select(r => r.S("SpanId"))),
            };
            var calls = await QueryAsync($"""
                SELECT ParentSpanId, SpanId, Type, Target, Op, OffsetMs, DurationMs, Ok, Error, HttpStatus,
                       DnsMs, ConnectMs, TlsMs, TtfbMs, toFloat64OrNull(Attributes['monitor.http.total_ms']) AS TotalMs, Bytes
                FROM {Db}.calls
                WHERE Site = {"{site:String}"}
                  AND Timestamp BETWEEN fromUnixTimestamp64Micro({"{from:Int64}"}) AND fromUnixTimestamp64Micro({"{to:Int64}"})
                  AND TraceId IN {"{traces:Array(String)}"} AND ParentSpanId IN {"{parents:Array(String)}"}
                ORDER BY OffsetMs
                LIMIT 20000
                """, sp, ct);
            foreach (var c in calls)
            {
                var type = c.S("Type");
                var detail = type == "http"
                    ? new SpanDetail(c.I("HttpStatus") is var s and > 0 ? s : null, c.ND("DnsMs"), c.ND("ConnectMs"), c.ND("TlsMs"),
                        c.ND("TtfbMs"), c.ND("TotalMs"), c.NL("Bytes"))
                    : null;
                var span = new Span(type, c.S("Target"), c.S("Op"), c.D("OffsetMs"), c.D("DurationMs"), c.B("Ok"), c.N("Error"), detail, c.S("SpanId"));
                if (!spans.TryGetValue(c.S("ParentSpanId"), out var list)) spans[c.S("ParentSpanId")] = list = [];
                list.Add(span);
            }
        }

        return rows.Select(r => new RequestRecord(
            Id: r.S("SpanId"),
            Ts: DateTimeOffset.FromUnixTimeMilliseconds(r.L("TsUs") / 1000).ToLocalTime(),
            Method: r.S("Method"), Route: r.S("Route"), Path: r.N("Path"), Query: r.N("Query"), Status: r.I("Status"),
            Ms: r.D("DurationMs"), CpuMs: r.D("CpuMs"), CpuUserMs: r.D("CpuUserMs"), CpuSysMs: r.D("CpuSysMs"),
            Mem: r.L("MemReserved"), MemPeak: r.L("MemPeak"), ExtMs: r.D("ExtMs"), ExtCalls: r.I("ExtCalls"),
            Detailed: r.B("Detailed"), Spans: spans.GetValueOrDefault(r.S("SpanId")) ?? [], SpansDropped: r.I("SpansDropped"),
            Ip: r.N("ClientIp"), Ua: r.N("UserAgent"), Referer: r.N("Referer"), Error: r.N("Error"), Server: r.S("Server"),
            TraceId: r.S("TraceId"), ParentSpanId: r.N("ParentSpanId"))).ToList();
    }

    /// <summary>Nome do site no ClickHouse (service.name do agente = Host do site), se não for o host da Url.</summary>
    public static string Site(TargetOptions t) =>
        t.Site is { Length: > 0 } s ? s : Uri.TryCreate(t.Url, UriKind.Absolute, out var u) ? u.Host : t.Id;

    private Dictionary<string, string> Params(TargetOptions t, TimeSpan window)
    {
        var secs = (long)Math.Clamp(window.TotalSeconds, 60, Opt.MaxWindowMinutes * 60.0);
        return new() { ["site"] = Site(t), ["secs"] = secs.ToString(CultureInfo.InvariantCulture) };
    }

    private static string ArrayLiteral(IEnumerable<string> values) =>
        "[" + string.Join(',', values.Select(v => "'" + v.Replace("\\", "\\\\").Replace("'", "\\'") + "'")) + "]";

    private async Task<List<Row>> QueryAsync(string sql, Dictionary<string, string> parameters, CancellationToken ct)
    {
        var qs = new List<string>
        {
            "database=" + Uri.EscapeDataString(Opt.Database),
            "default_format=JSONEachRow",
            "output_format_json_quote_64bit_integers=0",
            "output_format_json_quote_64bit_floats=0",
            "readonly=2",
            "max_execution_time=" + Opt.TimeoutSeconds,
        };
        qs.AddRange(parameters.Select(kv => $"param_{kv.Key}={Uri.EscapeDataString(kv.Value)}"));

        // Toda falha (configuração, rede, timeout, resposta cortada ou fora do formato) vira ClickHouseQueryException:
        // a API responde 503 com o motivo e a tela mostra "erro: ...". Só o cancelamento do próprio pedido passa direto.
        try
        {
            var url = new UriBuilder(Opt.Url!) { Path = "/", Query = string.Join('&', qs) };
            using var req = new HttpRequestMessage(HttpMethod.Post, url.Uri) { Content = new StringContent(sql, Encoding.UTF8) };
            req.Headers.Add("X-ClickHouse-User", Opt.User);
            var password = Environment.GetEnvironmentVariable("MONITOR_CLICKHOUSE_PASSWORD") ?? Opt.Password;
            if (password.Length > 0) req.Headers.Add("X-ClickHouse-Key", password);

            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(TimeSpan.FromSeconds(Math.Max(1, Opt.TimeoutSeconds) + 5));
            using var resp = await http.SendAsync(req, HttpCompletionOption.ResponseHeadersRead, cts.Token);
            if (!resp.IsSuccessStatusCode)
            {
                var text = await resp.Content.ReadAsStringAsync(cts.Token);
                throw new ClickHouseQueryException($"ClickHouse HTTP {(int)resp.StatusCode}: {(text.Length > 500 ? text[..500] : text).Trim()}");
            }

            var rows = new List<Row>();
            using var reader = new StreamReader(await resp.Content.ReadAsStreamAsync(cts.Token), Encoding.UTF8);
            while (await reader.ReadLineAsync(cts.Token) is { } line)
            {
                if (line.Length == 0) continue;
                using var doc = JsonDocument.Parse(line);
                rows.Add(new Row(doc.RootElement.Clone()));
            }
            return rows;
        }
        catch (Exception ex) when (ex is not ClickHouseQueryException && !ct.IsCancellationRequested)
        {
            var reason = ex switch
            {
                UriFormatException or ArgumentException => "Monitor:ClickHouse:Url inválida",
                TaskCanceledException => $"sem resposta em {Opt.TimeoutSeconds + 5}s",
                JsonException => "resposta fora do formato",
                _ => "inacessível",
            };
            throw new ClickHouseQueryException($"ClickHouse {reason} ({Opt.Url}): {ex.Message}");
        }
    }

    /// <summary>Uma linha JSONEachRow com leitores tolerantes (null, NaN, número como texto).</summary>
    private readonly record struct Row(JsonElement E)
    {
        private JsonElement? Get(string k) =>
            E.TryGetProperty(k, out var v) && v.ValueKind != JsonValueKind.Null ? v : null;

        public string S(string k) => Get(k) is { } v ? v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : v.GetRawText() : "";
        public string? N(string k) => S(k) is { Length: > 0 } s ? s : null;
        public double? ND(string k) => Get(k) is { } v
            ? v.ValueKind == JsonValueKind.Number ? v.GetDouble()
            : double.TryParse(v.GetString(), NumberStyles.Float, CultureInfo.InvariantCulture, out var d) && double.IsFinite(d) ? d : null
            : null;
        public double D(string k) => ND(k) ?? 0;
        public long? NL(string k) => ND(k) is { } d ? (long)d : null;
        public long L(string k) => NL(k) ?? 0;
        public int I(string k) => (int)Math.Clamp(L(k), int.MinValue, int.MaxValue);
        public bool B(string k) => Get(k) is { } v && (v.ValueKind == JsonValueKind.True || (v.ValueKind == JsonValueKind.Number && v.GetDouble() != 0));
        public DateTimeOffset T(string k) => DateTimeOffset.FromUnixTimeMilliseconds(L(k)).ToLocalTime();
        public IReadOnlyList<string> Strings(string k) => Get(k) is { ValueKind: JsonValueKind.Array } v
            ? v.EnumerateArray().Select(x => x.GetString() ?? "").ToList()
            : [];
    }
}
