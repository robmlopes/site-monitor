namespace Monitor.Panel.Monitoring;

/// <summary>Uma requisição (span SERVER do trace OpenTelemetry gravado pelo agente).</summary>
public sealed record RequestRecord(
    string Id,
    DateTimeOffset Ts,
    string Method,
    string Route,
    string? Path,
    string? Query,
    int Status,
    double Ms,
    double CpuMs,
    double CpuUserMs,
    double CpuSysMs,
    long Mem,
    long MemPeak,
    double ExtMs,
    int ExtCalls,
    bool Detailed,
    List<Span>? Spans,
    int SpansDropped,
    string? Ip,
    string? Ua,
    string? Referer,
    string? Error,
    string Server,
    string TraceId,
    string? ParentSpanId);

/// <summary>Uma chamada a outro sistema (banco, HTTP, SOAP, Redis...) feita durante a requisição.</summary>
public sealed record Span(
    string Type,
    string Target,
    string Op,
    double At,
    double Ms,
    bool Ok,
    string? Error,
    SpanDetail? Detail,
    string? Id = null);

/// <summary>Tempos detalhados de chamadas HTTP (ms).</summary>
public sealed record SpanDetail(int? Status, double? Dns, double? Connect, double? Tls, double? Ttfb, double? Total, long? Bytes);

public sealed record RouteStats(
    string Method,
    string Route,
    int Hits,
    int Ok,
    int ClientErrors,
    int ServerErrors,
    double AvgMs,
    double P95Ms,
    double MaxMs,
    double AvgCpuMs,
    double MaxCpuMs,
    double AvgMemPeak,
    long MaxMemPeak,
    double AvgExtMs,
    double AvgExtCalls,
    DateTimeOffset LastSeen,
    int LastStatus,
    string? LastError);

public sealed record ServerStats(
    string Server,
    int Hits,
    int ServerErrors,
    double AvgMs,
    double P95Ms,
    double AvgCpuMs,
    double AvgMemPeak,
    DateTimeOffset LastSeen);

public sealed record OperationStats(string Op, int Calls, int Errors, double AvgMs, double MaxMs);

/// <summary>Uma chamada filha (SQL, HTTP, Redis, sessão...) agregada dentro de uma rota.</summary>
public sealed record RouteChildStats(
    string Type,
    string Target,
    string Op,
    int Calls,
    int Requests,
    double PerRequest,
    int Errors,
    double AvgMs,
    double MaxMs,
    double TotalMs,
    double ShareOfRoute,
    string? LastError);

public sealed record DependencyStats(
    string Type,
    string Target,
    int Calls,
    int Errors,
    double AvgMs,
    double P95Ms,
    double MaxMs,
    double? AvgTtfbMs,
    DateTimeOffset LastSeen,
    string? LastError,
    IReadOnlyList<OperationStats> SlowestOps,
    IReadOnlyList<string> Routes);

/// <summary>Estatísticas por rota e por sistema externo, e feed de acessos recentes, em memória.</summary>
public sealed class RequestStore
{
    private const int RecentSize = 2000;
    private const int SamplesPerRoute = 300;
    private const int MaxOpsPerDependency = 200;
    private const int MaxChildrenPerRoute = 300;

    private readonly Dictionary<string, TargetRequests> _targets = [];
    private readonly Lock _sync = new();

    /// <summary>Cursor de leitura do endpoint traces de um servidor (nó) do site.</summary>
    public string? Cursor(string targetId, string node)
    {
        lock (_sync) return _targets.TryGetValue(targetId, out var t) && t.Cursors.TryGetValue(node, out var c) ? c : null;
    }

    public void Add(string targetId, string node, string? cursor, IEnumerable<RequestRecord> records)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) _targets[targetId] = t = new TargetRequests();
            t.Cursors[node] = cursor;

            foreach (var r in records)
            {
                t.Recent.Enqueue(r);
                if (t.Recent.Count > RecentSize) t.Recent.Dequeue();

                var key = (r.Method, r.Route);
                if (!t.Routes.TryGetValue(key, out var agg)) t.Routes[key] = agg = new RouteAgg();
                agg.Hits++;
                if (r.Status >= 500) agg.ServerErrors++;
                else if (r.Status >= 400) agg.ClientErrors++;
                else agg.Ok++;
                agg.Ms.Add(r.Ms);
                agg.CpuMs.Add(r.CpuMs);
                agg.MemPeak.Add(r.MemPeak);
                agg.ExtMs.Add(r.ExtMs);
                agg.ExtCalls += r.ExtCalls;

                if (!t.Servers.TryGetValue(r.Server, out var srv)) t.Servers[r.Server] = srv = new ServerAgg();
                srv.Ms.Add(r.Ms);
                srv.CpuMs.Add(r.CpuMs);
                srv.MemPeak.Add(r.MemPeak);
                if (r.Status >= 500) srv.ServerErrors++;
                srv.LastSeen = r.Ts;
                agg.Last = r;
                if (r.Error is not null) agg.LastError = r.Error;

                // Chamadas filhas desta rota, agrupadas por tipo + destino + operação.
                var seenInRequest = new HashSet<(string, string, string)>();
                foreach (var s in r.Spans ?? [])
                {
                    var ck = (s.Type, s.Target, s.Op);
                    if (!agg.Children.TryGetValue(ck, out var child))
                    {
                        if (agg.Children.Count >= MaxChildrenPerRoute) continue;
                        agg.Children[ck] = child = new OpAgg();
                    }
                    child.Calls++;
                    child.TotalMs += s.Ms;
                    child.MaxMs = Math.Max(child.MaxMs, s.Ms);
                    if (!s.Ok) { child.Errors++; child.LastError = s.Error; }
                    if (seenInRequest.Add(ck)) child.Requests++;
                }

                foreach (var s in r.Spans ?? [])
                {
                    var dk = (s.Type, s.Target);
                    if (!t.Dependencies.TryGetValue(dk, out var dep)) t.Dependencies[dk] = dep = new DependencyAgg();
                    dep.Ms.Add(s.Ms);
                    if (!s.Ok) { dep.Errors++; dep.LastError = s.Error; }
                    if (s.Detail?.Ttfb is { } ttfb) { dep.TtfbTotal += ttfb; dep.TtfbCount++; }
                    dep.LastSeen = r.Ts.AddMilliseconds(s.At);
                    dep.Routes[$"{r.Method} {r.Route}"] = dep.Routes.GetValueOrDefault($"{r.Method} {r.Route}") + 1;

                    if (dep.Ops.TryGetValue(s.Op, out var op) || dep.Ops.Count < MaxOpsPerDependency)
                    {
                        op ??= dep.Ops[s.Op] = new OpAgg();
                        op.Calls++;
                        op.TotalMs += s.Ms;
                        op.MaxMs = Math.Max(op.MaxMs, s.Ms);
                        if (!s.Ok) op.Errors++;
                    }
                }
            }
        }
    }

    public IReadOnlyList<RouteStats> Routes(string targetId)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) return [];
            return t.Routes
                .Select(kv =>
                {
                    var a = kv.Value;
                    return new RouteStats(
                        kv.Key.Method, kv.Key.Route, a.Hits, a.Ok, a.ClientErrors, a.ServerErrors,
                        a.Ms.Avg, a.Ms.P95(), a.Ms.Max,
                        a.CpuMs.Avg, a.CpuMs.Max, a.MemPeak.Avg, (long)a.MemPeak.Max,
                        a.ExtMs.Avg, (double)a.ExtCalls / a.Hits,
                        a.Last!.Ts, a.Last.Status, a.LastError);
                })
                .OrderByDescending(r => r.Hits)
                .ToList();
        }
    }

    public IReadOnlyList<RouteChildStats> RouteChildren(string targetId, string method, string route)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t) || !t.Routes.TryGetValue((method, route), out var a)) return [];
            var routeTotal = a.Ms.Avg * a.Hits;
            return a.Children
                .Select(kv => new RouteChildStats(
                    kv.Key.Type, kv.Key.Target, kv.Key.Op, kv.Value.Calls, kv.Value.Requests,
                    (double)kv.Value.Calls / a.Hits, kv.Value.Errors,
                    kv.Value.TotalMs / kv.Value.Calls, kv.Value.MaxMs, kv.Value.TotalMs,
                    routeTotal > 0 ? kv.Value.TotalMs / routeTotal : 0, kv.Value.LastError))
                .OrderByDescending(c => c.TotalMs)
                .ToList();
        }
    }

    public IReadOnlyList<DependencyStats> Dependencies(string targetId)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) return [];
            return t.Dependencies
                .Select(kv =>
                {
                    var d = kv.Value;
                    return new DependencyStats(
                        kv.Key.Type, kv.Key.Target, d.Ms.Count, d.Errors, d.Ms.Avg, d.Ms.P95(), d.Ms.Max,
                        d.TtfbCount == 0 ? null : d.TtfbTotal / d.TtfbCount,
                        d.LastSeen, d.LastError,
                        d.Ops.OrderByDescending(o => o.Value.TotalMs).Take(5)
                            .Select(o => new OperationStats(o.Key, o.Value.Calls, o.Value.Errors, o.Value.TotalMs / o.Value.Calls, o.Value.MaxMs))
                            .ToList(),
                        d.Routes.OrderByDescending(x => x.Value).Select(x => x.Key).Take(5).ToList());
                })
                .OrderByDescending(d => d.AvgMs * d.Calls)
                .ToList();
        }
    }

    public IReadOnlyList<ServerStats> Servers(string targetId)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) return [];
            return t.Servers
                .Select(kv => new ServerStats(kv.Key, kv.Value.Ms.Count, kv.Value.ServerErrors, kv.Value.Ms.Avg, kv.Value.Ms.P95(),
                    kv.Value.CpuMs.Avg, kv.Value.MemPeak.Avg, kv.Value.LastSeen))
                .OrderBy(s => s.Server)
                .ToList();
        }
    }

    public IReadOnlyList<RequestRecord> Recent(string targetId, string? method, string? route, string? server, string? trace, int limit)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) return [];
            return t.Recent.Reverse()
                .Where(r => (method is null || r.Method == method) && (route is null || r.Route == route)
                    && (server is null || r.Server == server) && (trace is null || r.TraceId == trace))
                .Take(limit)
                .ToList();
        }
    }

    private sealed class TargetRequests
    {
        public Dictionary<string, string?> Cursors { get; } = [];
        public Dictionary<string, ServerAgg> Servers { get; } = [];
        public Queue<RequestRecord> Recent { get; } = new();
        public Dictionary<(string Method, string Route), RouteAgg> Routes { get; } = [];
        public Dictionary<(string Type, string Target), DependencyAgg> Dependencies { get; } = [];
    }

    private sealed class RouteAgg
    {
        public int Hits, Ok, ClientErrors, ServerErrors, ExtCalls;
        public Metric Ms { get; } = new();
        public Metric CpuMs { get; } = new();
        public Metric MemPeak { get; } = new();
        public Metric ExtMs { get; } = new();
        public RequestRecord? Last;
        public string? LastError;
        public Dictionary<(string Type, string Target, string Op), OpAgg> Children { get; } = [];
    }

    private sealed class ServerAgg
    {
        public int ServerErrors;
        public DateTimeOffset LastSeen;
        public Metric Ms { get; } = new();
        public Metric CpuMs { get; } = new();
        public Metric MemPeak { get; } = new();
    }

    private sealed class DependencyAgg
    {
        public int Errors, TtfbCount;
        public double TtfbTotal;
        public DateTimeOffset LastSeen;
        public string? LastError;
        public Metric Ms { get; } = new();
        public Dictionary<string, OpAgg> Ops { get; } = [];
        public Dictionary<string, int> Routes { get; } = [];
    }

    private sealed class OpAgg
    {
        public int Calls, Errors, Requests;
        public double TotalMs, MaxMs;
        public string? LastError;
    }

    /// <summary>Média e máximo de tudo, p95 sobre as últimas amostras.</summary>
    private sealed class Metric
    {
        private readonly Queue<double> _samples = new();
        private double _total;
        public int Count { get; private set; }
        public double Max { get; private set; }
        public double Avg => Count == 0 ? 0 : _total / Count;

        public void Add(double v)
        {
            Count++;
            _total += v;
            Max = Math.Max(Max, v);
            _samples.Enqueue(v);
            if (_samples.Count > SamplesPerRoute) _samples.Dequeue();
        }

        public double P95()
        {
            if (_samples.Count == 0) return 0;
            var sorted = _samples.Order().ToList();
            return sorted[(int)Math.Ceiling(sorted.Count * 0.95) - 1];
        }
    }
}
