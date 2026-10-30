using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.Extensions.Options;

namespace Monitor.Panel.Monitoring;

/// <summary>Lê de forma incremental o log de requisições do site (monitor/requests.php).</summary>
public sealed class RequestCollector(
    IOptions<MonitorOptions> options, IHttpClientFactory httpFactory, RequestStore store,
    ILogger<RequestCollector> logger) : BackgroundService
{
    private static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web);

    private sealed record Page(long Next, bool Reset, List<RequestRecord> Requests);

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        var targets = options.Value.Targets.Where(t => t.RequestsUrl is not null).ToList();
        if (targets.Count == 0) return;

        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(options.Value.RequestsIntervalSeconds));
        do
        {
            foreach (var t in targets)
            {
                try { await PullAsync(t, ct); }
                catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException && !ct.IsCancellationRequested)
                {
                    logger.LogDebug("Sem log de requisições de {Target}: {Error}", t.Name, ex.Message);
                }
            }
        } while (await timer.WaitForNextTickAsync(ct));
    }

    private async Task PullAsync(TargetOptions target, CancellationToken ct)
    {
        var http = httpFactory.CreateClient("monitor");
        Page? page;
        do
        {
            var offset = store.Offset(target.Id);
            using var req = new HttpRequestMessage(HttpMethod.Get, $"{target.RequestsUrl}?offset={offset}");
            if (target.Token is not null) req.Headers.Add("X-Monitor-Token", target.Token);
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(TimeSpan.FromSeconds(target.TimeoutSeconds));

            using var resp = await http.SendAsync(req, cts.Token);
            resp.EnsureSuccessStatusCode();
            page = await resp.Content.ReadFromJsonAsync<Page>(Json, cts.Token);
            if (page is null) return;

            if (page.Reset) logger.LogInformation("{Target}: log de requisições rotacionado, relendo do início", target.Name);
            store.Add(target.Id, page.Next, page.Requests);
        } while (page.Requests.Count >= 500); // ainda há backlog
    }
}
