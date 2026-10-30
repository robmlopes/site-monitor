using System.Text.Json;
using Microsoft.Extensions.Options;

namespace Monitor.Panel.Monitoring;

/// <summary>
/// Lê de forma incremental os traces (OTLP/JSON) de cada servidor de cada site (/_monitor/traces.php).
/// Cada servidor tem o próprio cursor: os logs são locais a ele.
/// </summary>
public sealed class RequestCollector(
    IOptions<MonitorOptions> options, IHttpClientFactory httpFactory, RequestStore store,
    ILogger<RequestCollector> logger) : BackgroundService
{
    private const int PageSize = 500;

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        var nodes = options.Value.Targets.SelectMany(t => t.AllNodes().Select(n => (t, n))).ToList();
        if (nodes.Count == 0) return;

        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(options.Value.RequestsIntervalSeconds));
        do
        {
            await Task.WhenAll(nodes.Select(async x =>
            {
                try { await PullAsync(x.t, x.n, ct); }
                catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException && !ct.IsCancellationRequested)
                {
                    logger.LogDebug("Sem traces de {Target}/{Node}: {Error}", x.t.Name, x.n.Name, ex.Message);
                }
            }));
        } while (await timer.WaitForNextTickAsync(ct));
    }

    private async Task PullAsync(TargetOptions target, NodeOptions node, CancellationToken ct)
    {
        var http = httpFactory.CreateClient(target.HttpClientName);
        int count;
        do
        {
            var cursor = store.Cursor(target.Id, node.Name);
            var url = cursor is null ? node.TracesUrl : $"{node.TracesUrl}?cursor={Uri.EscapeDataString(cursor)}";
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            if (target.Token is not null) req.Headers.Add("X-Monitor-Token", target.Token);
            if (node.HostHeader is not null) req.Headers.Host = node.HostHeader;
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(TimeSpan.FromSeconds(target.TimeoutSeconds));

            using var resp = await http.SendAsync(req, cts.Token);
            resp.EnsureSuccessStatusCode();
            using var doc = await JsonDocument.ParseAsync(await resp.Content.ReadAsStreamAsync(cts.Token), cancellationToken: cts.Token);
            var root = doc.RootElement;
            var server = root.TryGetProperty("server", out var s) ? s.GetString() ?? node.Name : node.Name;

            var traces = root.GetProperty("traces");
            count = traces.GetArrayLength();
            var records = traces.EnumerateArray().SelectMany(t => Otlp.ToRequests(t, server)).ToList();
            store.Add(target.Id, node.Name, root.GetProperty("cursor").GetString(), records);
        } while (count >= PageSize); // ainda há backlog
    }
}
