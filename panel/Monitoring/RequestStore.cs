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
    long Mem,
    string? Ip,
    string? Ua,
    string? Referer,
    string? Error);

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
    DateTimeOffset LastSeen,
    int LastStatus,
    string? LastError);

/// <summary>Estatísticas por rota e feed de acessos recentes, em memória.</summary>
public sealed class RequestStore
{
    private const int RecentSize = 2000;
    private const int SamplesPerRoute = 300;

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
                agg.TotalMs += r.Ms;
                agg.MaxMs = Math.Max(agg.MaxMs, r.Ms);
                agg.Samples.Enqueue(r.Ms);
                if (agg.Samples.Count > SamplesPerRoute) agg.Samples.Dequeue();
                agg.Last = r;
                if (r.Error is not null) agg.LastError = r.Error;
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
                    var sorted = a.Samples.Order().ToList();
                    return new RouteStats(
                        kv.Key.Method, kv.Key.Route, a.Hits, a.Ok, a.ClientErrors, a.ServerErrors,
                        a.TotalMs / a.Hits, sorted[(int)Math.Ceiling(sorted.Count * 0.95) - 1], a.MaxMs,
                        a.Last!.Ts, a.Last.Status, a.LastError);
                })
                .OrderByDescending(r => r.Hits)
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
    }

    private sealed class RouteAgg
    {
        public int Hits, Ok, ClientErrors, ServerErrors;
        public double TotalMs, MaxMs;
        public Queue<double> Samples { get; } = new();
        public RequestRecord? Last;
        public string? LastError;
    }
}
