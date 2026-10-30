using System.Diagnostics;
using System.Text.Json;

namespace Monitor.Panel.Monitoring;

public sealed class SiteChecker(IHttpClientFactory httpFactory, StatusStore store)
{
    public async Task<CheckResult> CheckAsync(TargetOptions target, CancellationToken ct)
    {
        var http = httpFactory.CreateClient("monitor");
        var timeout = TimeSpan.FromSeconds(target.TimeoutSeconds);

        var (reachable, code, latency, error) = await ProbeAsync(http, target.Url, timeout, ct);

        string? healthStatus = null;
        double? healthLatency = null;
        JsonElement? health = null;
        if (target.HealthUrl is not null && reachable)
        {
            var sw = Stopwatch.StartNew();
            try
            {
                using var req = new HttpRequestMessage(HttpMethod.Get, target.HealthUrl);
                if (target.Token is not null) req.Headers.Add("X-Monitor-Token", target.Token);
                using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
                cts.CancelAfter(timeout);
                using var resp = await http.SendAsync(req, cts.Token);
                var body = await resp.Content.ReadAsStringAsync(cts.Token);
                healthLatency = sw.Elapsed.TotalMilliseconds;
                if (resp.IsSuccessStatusCode)
                {
                    health = JsonDocument.Parse(body).RootElement.Clone();
                    healthStatus = health.Value.TryGetProperty("status", out var st) ? st.GetString() : "invalido";
                }
                else
                {
                    healthStatus = $"HTTP {(int)resp.StatusCode}";
                }
            }
            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException)
            {
                healthStatus = ex is JsonException ? "json inválido" : "sem resposta";
            }
        }

        var result = new CheckResult(DateTimeOffset.Now, reachable, code, latency, error, healthStatus, healthLatency, health);
        store.Record(target, result);
        return result;
    }

    private static async Task<(bool, int?, double, string?)> ProbeAsync(
        HttpClient http, string url, TimeSpan timeout, CancellationToken ct)
    {
        var sw = Stopwatch.StartNew();
        try
        {
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(timeout);
            using var resp = await http.GetAsync(url, HttpCompletionOption.ResponseContentRead, cts.Token);
            return (true, (int)resp.StatusCode, sw.Elapsed.TotalMilliseconds, null);
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            return (false, null, sw.Elapsed.TotalMilliseconds, $"timeout ({timeout.TotalSeconds:F0}s)");
        }
        catch (HttpRequestException ex)
        {
            return (false, null, sw.Elapsed.TotalMilliseconds, ex.Message);
        }
    }
}

public sealed class PollingService(
    StatusStore store, SiteChecker checker, Microsoft.Extensions.Options.IOptions<MonitorOptions> options,
    ILogger<PollingService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        var interval = TimeSpan.FromSeconds(options.Value.IntervalSeconds);
        logger.LogInformation("Monitorando {Count} alvo(s) a cada {Interval}s", store.Targets.Count(), interval.TotalSeconds);

        using var timer = new PeriodicTimer(interval);
        do
        {
            await Task.WhenAll(store.Targets.Select(async t =>
            {
                try { await checker.CheckAsync(t, ct); }
                catch (Exception ex) when (ex is not OperationCanceledException)
                {
                    logger.LogError(ex, "Falha inesperada ao checar {Target}", t.Name);
                }
            }));
        } while (await timer.WaitForNextTickAsync(ct));
    }
}
