using System.Text.Json.Serialization;
using Monitor.Panel.Monitoring;

// Codificações do Windows (CP1252, ISO-8859-1...) usadas pelos sites legados monitorados.
System.Text.Encoding.RegisterProvider(System.Text.CodePagesEncodingProvider.Instance);

// Iniciado de outra pasta (ex.: D:\> D:\monitor\panel\Monitor.Panel.exe, ou como serviço do Windows),
// usa a pasta do executável para achar appsettings.json e wwwroot. No "dotnet run" nada muda.
var contentRoot = File.Exists(Path.Combine(Directory.GetCurrentDirectory(), "appsettings.json"))
    ? Directory.GetCurrentDirectory()
    : AppContext.BaseDirectory;
var builder = WebApplication.CreateBuilder(new WebApplicationOptions { Args = args, ContentRootPath = contentRoot });
// Sites desta máquina, fora do git. Os alvos se mesclam por posição com os do appsettings.json.
// Sem reloadOnChange: as opções são lidas uma vez ao iniciar (mudanças valem ao reiniciar o pool), e um arquivo
// salvo pela metade ou com JSON inválido não pode afetar o painel em execução.
builder.Configuration.AddJsonFile("appsettings.Local.json", optional: true, reloadOnChange: false);

builder.Services.Configure<MonitorOptions>(builder.Configuration.GetSection("Monitor"));
builder.Services.AddSingleton<StatusStore>();
builder.Services.AddSingleton<SiteChecker>();
builder.Services.AddHostedService<PollingService>();
// Fonte das requisições: ClickHouse (alimentado pelo coletor de cada servidor) ou memória (puxa traces.php de cada servidor).
var clickHouse = builder.Configuration.GetSection("Monitor:ClickHouse").Get<ClickHouseOptions>() ?? new();
if (!string.IsNullOrWhiteSpace(clickHouse.Url))
{
    builder.Services.AddHttpClient<IRequestSource, ClickHouseRequestSource>(c => c.Timeout = Timeout.InfiniteTimeSpan)
        .ConfigurePrimaryHttpMessageHandler(() => new HttpClientHandler
        {
            ServerCertificateCustomValidationCallback = clickHouse.IgnoreTlsErrors
                ? HttpClientHandler.DangerousAcceptAnyServerCertificateValidator : null,
        });
}
else
{
    builder.Services.AddSingleton<RequestStore>();
    builder.Services.AddSingleton<IRequestSource, MemoryRequestSource>();
    builder.Services.AddHostedService<RequestCollector>();
}
builder.Services.AddHttpClient("monitor", c => c.DefaultRequestHeaders.UserAgent.ParseAdd("MonitorPanel/0.1"));
builder.Services.AddHttpClient("monitor-insecure", c => c.DefaultRequestHeaders.UserAgent.ParseAdd("MonitorPanel/0.1"))
    .ConfigurePrimaryHttpMessageHandler(() => new HttpClientHandler
    {
        ServerCertificateCustomValidationCallback = HttpClientHandler.DangerousAcceptAnyServerCertificateValidator,
    });
builder.Services.ConfigureHttpJsonOptions(o => o.SerializerOptions.Converters.Add(new JsonStringEnumConverter()));

var app = builder.Build();

app.UseDefaultFiles();
app.UseStaticFiles(new StaticFileOptions
{
    // O dashboard muda com frequência no protótipo: sempre revalidar.
    OnPrepareResponse = ctx => ctx.Context.Response.Headers.CacheControl = "no-cache",
});

var api = app.MapGroup("/api");

api.MapGet("/targets", (StatusStore store) => store.Summaries());

api.MapGet("/targets/{id}/history", (string id, StatusStore store) =>
    store.Find(id) is null ? Results.NotFound() : Results.Ok(store.History(id)));

api.MapPost("/targets/{id}/check", async (string id, StatusStore store, SiteChecker checker, CancellationToken ct) =>
    store.Find(id) is { } t ? Results.Ok(await checker.CheckAsync(t, ct)) : Results.NotFound());

// Período das estatísticas (?minutes=); só o ClickHouse usa — a memória mostra tudo desde que o painel subiu.
TimeSpan Window(int? minutes) => TimeSpan.FromMinutes(minutes is > 0 ? minutes.Value : clickHouse.DefaultWindowMinutes);

// ClickHouse fora ou consulta recusada: 503 com a mensagem, para a tela mostrar o motivo.
// Qualquer outra falha: 500 com a mensagem (a tela mostra "erro: ...") e o detalhe no log, nunca uma página vazia.
api.AddEndpointFilter(async (ctx, next) =>
{
    try { return await next(ctx); }
    catch (ClickHouseQueryException ex) { return Results.Problem(ex.Message, statusCode: StatusCodes.Status503ServiceUnavailable); }
    catch (Exception ex) when (!ctx.HttpContext.RequestAborted.IsCancellationRequested)
    {
        ctx.HttpContext.RequestServices.GetRequiredService<ILogger<Program>>()
            .LogError(ex, "Falha em {Path}", ctx.HttpContext.Request.Path);
        return Results.Problem(ex.Message, statusCode: StatusCodes.Status500InternalServerError);
    }
});

api.MapGet("/info", (IRequestSource source) => new
{
    source = source.Name,
    defaultMinutes = clickHouse.DefaultWindowMinutes,
    maxMinutes = clickHouse.MaxWindowMinutes,
});

api.MapGet("/targets/{id}/routes", async (string id, int? minutes, StatusStore store, IRequestSource source, CancellationToken ct) =>
    store.Find(id) is { } t ? Results.Ok(await source.RoutesAsync(t, Window(minutes), ct)) : Results.NotFound());

api.MapGet("/targets/{id}/routes/children", async (string id, string method, string route, int? minutes, StatusStore store, IRequestSource source, CancellationToken ct) =>
    store.Find(id) is { } t ? Results.Ok(await source.RouteChildrenAsync(t, method, route, Window(minutes), ct)) : Results.NotFound());

api.MapGet("/targets/{id}/dependencies", async (string id, int? minutes, StatusStore store, IRequestSource source, CancellationToken ct) =>
    store.Find(id) is { } t ? Results.Ok(await source.DependenciesAsync(t, Window(minutes), ct)) : Results.NotFound());

api.MapGet("/targets/{id}/requests", async (string id, string? method, string? route, string? server, string? trace, int? limit, int? minutes,
        StatusStore store, IRequestSource source, CancellationToken ct) =>
    store.Find(id) is { } t
        ? Results.Ok(await source.RecentAsync(t, new RequestFilter(method, route, server, trace, Math.Clamp(limit ?? 50, 1, 500)), Window(minutes), ct))
        : Results.NotFound());

api.MapGet("/targets/{id}/servers", async (string id, int? minutes, StatusStore store, IRequestSource source, CancellationToken ct) =>
    store.Find(id) is { } t ? Results.Ok(await source.ServersAsync(t, Window(minutes), ct)) : Results.NotFound());

api.MapGet("/incidents",(StatusStore store) => store.Incidents());

app.Run();
