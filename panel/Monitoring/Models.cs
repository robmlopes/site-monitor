using System.Text.Json;

namespace Monitor.Panel.Monitoring;

public sealed class MonitorOptions
{
    public int IntervalSeconds { get; set; } = 10;
    public int HistorySize { get; set; } = 360;
    /// <summary>Falhas consecutivas antes de marcar o alvo como DOWN.</summary>
    public int FailuresToDown { get; set; } = 2;
    /// <summary>Intervalo de leitura do log de requisições (RequestsUrl).</summary>
    public int RequestsIntervalSeconds { get; set; } = 2;
    public List<TargetOptions> Targets { get; set; } = [];
}

public sealed class TargetOptions
{
    public string Id { get; set; } = "";
    public string Name { get; set; } = "";
    public string Url { get; set; } = "";
    public string? HealthUrl { get; set; }
    /// <summary>Endpoint monitor/requests.php do site: acessos por rota gravados pelo agent.php.</summary>
    public string? RequestsUrl { get; set; }
    public string? Token { get; set; }
    public int TimeoutSeconds { get; set; } = 5;
    public int SlowMs { get; set; } = 1000;
}

public enum TargetStatus { Unknown, Up, Degraded, Down }

public sealed record CheckResult(
    DateTimeOffset At,
    bool Reachable,
    int? StatusCode,
    double LatencyMs,
    string? Error,
    string? HealthStatus,
    double? HealthLatencyMs,
    JsonElement? Health)
{
    public bool Failed => !Reachable || StatusCode is null or >= 500;
}

public sealed record Incident(
    Guid Id,
    string TargetId,
    string TargetName,
    TargetStatus Status,
    string Reason,
    DateTimeOffset StartedAt)
{
    public DateTimeOffset? EndedAt { get; set; }
}

public sealed record TargetSummary(
    string Id,
    string Name,
    string Url,
    TargetStatus Status,
    DateTimeOffset? StatusSince,
    CheckResult? Last,
    int Samples,
    double? UptimePercent,
    double? AvgLatencyMs,
    double? P95LatencyMs);
