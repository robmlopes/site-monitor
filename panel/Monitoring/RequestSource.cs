namespace Monitor.Panel.Monitoring;

/// <summary>
/// De onde o painel lê requisições e chamadas: da memória (puxando /_monitor/traces.php de cada servidor) ou do
/// ClickHouse (alimentado pelo coletor de cada servidor). <paramref name="window"/> só vale para o ClickHouse;
/// a memória mostra tudo desde que o painel subiu.
/// </summary>
public interface IRequestSource
{
    string Name { get; }
    Task<IReadOnlyList<RouteStats>> RoutesAsync(TargetOptions target, TimeSpan window, CancellationToken ct);
    Task<IReadOnlyList<RouteChildStats>> RouteChildrenAsync(TargetOptions target, string method, string route, TimeSpan window, CancellationToken ct);
    Task<IReadOnlyList<DependencyStats>> DependenciesAsync(TargetOptions target, TimeSpan window, CancellationToken ct);
    Task<IReadOnlyList<ServerStats>> ServersAsync(TargetOptions target, TimeSpan window, CancellationToken ct);
    Task<IReadOnlyList<RequestRecord>> RecentAsync(TargetOptions target, RequestFilter filter, TimeSpan window, CancellationToken ct);
}

public sealed record RequestFilter(string? Method, string? Route, string? Server, string? Trace, int Limit);

public sealed class MemoryRequestSource(RequestStore store) : IRequestSource
{
    public string Name => "memory";

    public Task<IReadOnlyList<RouteStats>> RoutesAsync(TargetOptions t, TimeSpan window, CancellationToken ct) =>
        Task.FromResult(store.Routes(t.Id));

    public Task<IReadOnlyList<RouteChildStats>> RouteChildrenAsync(TargetOptions t, string method, string route, TimeSpan window, CancellationToken ct) =>
        Task.FromResult(store.RouteChildren(t.Id, method, route));

    public Task<IReadOnlyList<DependencyStats>> DependenciesAsync(TargetOptions t, TimeSpan window, CancellationToken ct) =>
        Task.FromResult(store.Dependencies(t.Id));

    public Task<IReadOnlyList<ServerStats>> ServersAsync(TargetOptions t, TimeSpan window, CancellationToken ct) =>
        Task.FromResult(store.Servers(t.Id));

    public Task<IReadOnlyList<RequestRecord>> RecentAsync(TargetOptions t, RequestFilter f, TimeSpan window, CancellationToken ct) =>
        Task.FromResult(store.Recent(t.Id, f.Method, f.Route, f.Server, f.Trace, f.Limit));
}
