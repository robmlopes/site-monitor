using System.Text.Json;

namespace Monitor.Panel.Monitoring;

public sealed class MonitorOptions
{
    public int IntervalSeconds { get; set; } = 10;
    public int HistorySize { get; set; } = 360;
    /// <summary>Falhas consecutivas antes de marcar o alvo como DOWN.</summary>
    public int FailuresToDown { get; set; } = 2;
    /// <summary>Intervalo de leitura dos traces de cada servidor (TracesUrl/Nodes).</summary>
    public int RequestsIntervalSeconds { get; set; } = 2;
    public List<TargetOptions> Targets { get; set; } = [];
}

public sealed class TargetOptions
{
    public string Id { get; set; } = "";
    public string Name { get; set; } = "";
    public string Url { get; set; } = "";
    /// <summary>URL da checagem de uptime, se não for a própria Url (ex.: um arquivo estático leve).</summary>
    public string? CheckUrl { get; set; }
    public string? HealthUrl { get; set; }
    /// <summary>Endpoint /_monitor/traces.php do site (um servidor). Para vários servidores, use Nodes.</summary>
    public string? TracesUrl { get; set; }
    /// <summary>Servidores do site, lidos um a um (os logs são locais de cada servidor, não passam pelo balanceador).</summary>
    public List<NodeOptions> Nodes { get; set; } = [];

    public IEnumerable<NodeOptions> AllNodes() =>
        Nodes.Count > 0 ? Nodes : TracesUrl is null ? [] : [new NodeOptions { Name = "padrão", TracesUrl = TracesUrl }];
    public string? Token { get; set; }
    public int TimeoutSeconds { get; set; } = 5;
    public int SlowMs { get; set; } = 1000;
    /// <summary>Aceita certificado HTTPS inválido/autoassinado (sites de laboratório no IIS local).</summary>
    public bool IgnoreTlsErrors { get; set; }

    public string HttpClientName => IgnoreTlsErrors ? "monitor-insecure" : "monitor";
}

public sealed class NodeOptions
{
    /// <summary>Identificação do nó no painel (o nome real do servidor vem dos próprios traces).</summary>
    public string Name { get; set; } = "";
    /// <summary>Endereço interno do servidor, ex.: https://10.0.0.11/_monitor/traces.php</summary>
    public string TracesUrl { get; set; } = "";
    /// <summary>Host do site quando o endereço é IP/nome do servidor (o agente separa os dados por site pelo Host).</summary>
    public string? HostHeader { get; set; }
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
