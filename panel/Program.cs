using System.Text.Json.Serialization;
using Monitor.Panel.Monitoring;

// Codificações do Windows (CP1252, ISO-8859-1...) usadas pelos sites legados monitorados.
System.Text.Encoding.RegisterProvider(System.Text.CodePagesEncodingProvider.Instance);

var builder = WebApplication.CreateBuilder(args);
// Sites desta máquina, fora do git. Os alvos se mesclam por posição com os do appsettings.json.
builder.Configuration.AddJsonFile("appsettings.Local.json", optional: true, reloadOnChange: true);

builder.Services.Configure<MonitorOptions>(builder.Configuration.GetSection("Monitor"));
builder.Services.AddSingleton<StatusStore>();
builder.Services.AddSingleton<SiteChecker>();
builder.Services.AddHostedService<PollingService>();
builder.Services.AddSingleton<RequestStore>();
builder.Services.AddHostedService<RequestCollector>();
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

api.MapGet("/targets/{id}/routes", (string id, StatusStore store, RequestStore requests) =>
    store.Find(id) is null ? Results.NotFound() : Results.Ok(requests.Routes(id)));

api.MapGet("/targets/{id}/routes/children", (string id, string method, string route, StatusStore store, RequestStore requests) =>
    store.Find(id) is null ? Results.NotFound() : Results.Ok(requests.RouteChildren(id, method, route)));

api.MapGet("/targets/{id}/dependencies", (string id, StatusStore store, RequestStore requests) =>
    store.Find(id) is null ? Results.NotFound() : Results.Ok(requests.Dependencies(id)));

api.MapGet("/targets/{id}/requests", (string id, string? method, string? route, string? server, string? trace, int? limit, StatusStore store, RequestStore requests) =>
    store.Find(id) is null ? Results.NotFound() : Results.Ok(requests.Recent(id, method, route, server, trace, Math.Clamp(limit ?? 50, 1, 500))));

api.MapGet("/targets/{id}/servers", (string id, StatusStore store, RequestStore requests) =>
    store.Find(id) is null ? Results.NotFound() : Results.Ok(requests.Servers(id)));

api.MapGet("/incidents",(StatusStore store) => store.Incidents());

app.Run();
