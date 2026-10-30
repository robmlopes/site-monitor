namespace Monitor.Panel.Monitoring;

/// <summary>Uma requisição registrada pelo monitor/agent.php no site.</summary>
public sealed record RequestRecord(
    string Id,
    DateTimeOffset Ts,
    string Method,
    string Route,
    string? Query,
    int Status,
    double Ms,
    double CpuMs,
    double CpuUserMs,
    double CpuSysMs,
    long Mem,
    long MemPeak,
    double ExtMs,
    List<Span>? Spans,
    int SpansDropped,
    string? Ip,
    string? Ua,
    string? Referer,
    string? Error);

/// <summary>Uma chamada a outro sistema (banco, HTTP, SOAP, Redis...) feita durante a requisição.</summary>
public sealed record Span(
    string Type,
    string Target,
    string Op,
    double At,
    double Ms,
    bool Ok,
    string? Error,
    SpanDetail? Detail);

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

public sealed record OperationStats(string Op, int Calls, int Errors, double AvgMs, double MaxMs);

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

    private readonly Dictionary<string, TargetRequests> _targets = [];
    private readonly Lock _sync = new();

    public long Offset(string targetId)
    {
        lock (_sync) return _targets.TryGetValue(targetId, out var t) ? t.Offset : 0;
    }

    public void Add(string targetId, long nextOffset, IEnumerable<RequestRecord> records)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) _targets[targetId] = t = new TargetRequests();
            t.Offset = nextOffset;

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
                agg.ExtCalls += r.Spans?.Count ?? 0;
                agg.Last = r;
                if (r.Error is not null) agg.LastError = r.Error;

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

    public IReadOnlyList<RequestRecord> Recent(string targetId, string? method, string? route, int limit)
    {
        lock (_sync)
        {
            if (!_targets.TryGetValue(targetId, out var t)) return [];
            return t.Recent.Reverse()
                .Where(r => (method is null || r.Method == method) && (route is null || r.Route == route))
                .Take(limit)
                .ToList();
        }
    }

    private sealed class TargetRequests
    {
        public long Offset { get; set; }
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
        public int Calls, Errors;
        public double TotalMs, MaxMs;
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
