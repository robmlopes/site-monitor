using Monitor.Collector;

// Coletor: lê os traces gravados pelo agente PHP (data/<site>/traces-*.jsonl) e envia ao ClickHouse.
// Roda como serviço do Windows (sc.exe create) ou no console (dotnet run / Monitor.Collector.exe).

// Iniciado de outra pasta (serviço do Windows começa em System32), usa a pasta do executável.
var contentRoot = File.Exists(Path.Combine(Directory.GetCurrentDirectory(), "appsettings.json"))
    ? Directory.GetCurrentDirectory()
    : AppContext.BaseDirectory;

var builder = Host.CreateApplicationBuilder(new HostApplicationBuilderSettings { Args = args, ContentRootPath = contentRoot });
// Configuração desta máquina (endereço, usuário e senha do ClickHouse), fora do git.
builder.Configuration.AddJsonFile("appsettings.Local.json", optional: true, reloadOnChange: false);
builder.Configuration.AddEnvironmentVariables("MONITOR_");

builder.Services.AddWindowsService(o => o.ServiceName = "MonitorCollector");
builder.Services.Configure<CollectorOptions>(builder.Configuration.GetSection("Collector"));

var ch = builder.Configuration.GetSection("Collector:ClickHouse").Get<ClickHouseOptions>() ?? new();
builder.Services.AddHttpClient<ClickHouseClient>(c =>
    {
        c.Timeout = Timeout.InfiniteTimeSpan; // o timeout é por requisição (ClickHouse:TimeoutSeconds)
        c.DefaultRequestHeaders.UserAgent.ParseAdd("MonitorCollector/1.0");
    })
    .ConfigurePrimaryHttpMessageHandler(() => new SocketsHttpHandler
    {
        PooledConnectionLifetime = TimeSpan.FromMinutes(5),
        SslOptions = ch.IgnoreTlsErrors
            ? new System.Net.Security.SslClientAuthenticationOptions { RemoteCertificateValidationCallback = (_, _, _, _) => true }
            : new System.Net.Security.SslClientAuthenticationOptions(),
    });
builder.Services.AddHostedService<FileShipper>();

builder.Build().Run();
