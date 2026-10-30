using Microsoft.Extensions.Options;

namespace Monitor.Panel.Monitoring;

/// <summary>Estado em memória: histórico por alvo, status atual e incidentes.</summary>
public sealed class StatusStore(IOptions<MonitorOptions> options, ILogger<StatusStore> logger)
{
    private readonly MonitorOptions _opt = options.Value;
    private readonly Dictionary<string, TargetState> _targets =
        options.Value.Targets.ToDictionary(t => t.Id, t => new TargetState(t));
    private readonly List<Incident> _incidents = [];
    private readonly Lock _incidentLock = new();

    public IEnumerable<TargetOptions> Targets => _targets.Values.Select(s => s.Target);

    public TargetOptions? Find(string id) => _targets.TryGetValue(id, out var s) ? s.Target : null;

    public void Record(TargetOptions target, CheckResult result)
    {
        var state = _targets[target.Id];
        lock (state.Sync)
        {
            state.History.Enqueue(result);
            while (state.History.Count > _opt.HistorySize) state.History.Dequeue();

            state.ConsecutiveFailures = result.Failed ? state.ConsecutiveFailures + 1 : 0;

            var (next, reason) = Classify(target, result, state.ConsecutiveFailures);
            if (next == state.Status) return;

            var previous = state.Status;
            state.Status = next;
            state.StatusSince = result.At;
            Transition(target, previous, next, reason, result.At);
        }
    }

    private (TargetStatus, string) Classify(TargetOptions t, CheckResult r, int failures)
    {
        if (r.Failed)
        {
            var reason = r.Error ?? $"HTTP {r.StatusCode}";
            return failures >= _opt.FailuresToDown ? (TargetStatus.Down, reason) : (TargetStatus.Degraded, reason);
        }
        if (r.LatencyMs > t.SlowMs) return (TargetStatus.Degraded, $"lento: {r.LatencyMs:F0} ms (limite {t.SlowMs} ms)");
        if (t.HealthUrl is not null && r.HealthStatus != "ok")
            return (TargetStatus.Degraded, $"health: {r.HealthStatus ?? "sem resposta"}");
        return (TargetStatus.Up, "ok");
    }

    private void Transition(TargetOptions t, TargetStatus from, TargetStatus to, string reason, DateTimeOffset at)
    {
        lock (_incidentLock)
        {
            var open = _incidents.LastOrDefault(i => i.TargetId == t.Id && i.EndedAt is null);
            if (open is not null) open.EndedAt = at;

            if (to is TargetStatus.Down or TargetStatus.Degraded)
            {
                _incidents.Add(new Incident(Guid.NewGuid(), t.Id, t.Name, to, reason, at));
                if (_incidents.Count > 500) _incidents.RemoveAt(0);
            }
        }

        if (to == TargetStatus.Up)
            logger.LogInformation("{Target}: {From} -> UP", t.Name, from);
        else
            logger.LogWarning("{Target}: {From} -> {To} ({Reason})", t.Name, from, to, reason);
    }

    public IReadOnlyList<TargetSummary> Summaries() => _targets.Values.Select(Summarize).ToList();

    public IReadOnlyList<CheckResult> History(string id)
    {
        var state = _targets[id];
        lock (state.Sync) return state.History.ToList();
    }

    public IReadOnlyList<Incident> Incidents()
    {
        lock (_incidentLock) return _incidents.AsEnumerable().Reverse().ToList();
    }

    private static TargetSummary Summarize(TargetState s)
    {
        List<CheckResult> h;
        lock (s.Sync) h = s.History.ToList();

        var ok = h.Where(r => !r.Failed).Select(r => r.LatencyMs).Order().ToList();
        return new TargetSummary(
            s.Target.Id, s.Target.Name, s.Target.Url, s.Status, s.StatusSince,
            h.LastOrDefault(),
            h.Count,
            h.Count == 0 ? null : 100.0 * h.Count(r => !r.Failed) / h.Count,
            ok.Count == 0 ? null : ok.Average(),
            ok.Count == 0 ? null : ok[(int)Math.Ceiling(ok.Count * 0.95) - 1]);
    }

    private sealed class TargetState(TargetOptions target)
    {
        public readonly Lock Sync = new();
        public TargetOptions Target { get; } = target;
        public Queue<CheckResult> History { get; } = new();
        public int ConsecutiveFailures { get; set; }
        public TargetStatus Status { get; set; } = TargetStatus.Unknown;
        public DateTimeOffset? StatusSince { get; set; }
    }
}
