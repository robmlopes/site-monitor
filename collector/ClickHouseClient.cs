using System.IO.Compression;
using System.Net.Http.Headers;
using System.Reflection;
using Microsoft.Extensions.Options;

namespace Monitor.Collector;

/// <summary>
/// Cliente mínimo da interface HTTP do ClickHouse (porta 8123/8443): DDL e INSERT ... FORMAT JSONEachRow com gzip.
/// </summary>
public sealed class ClickHouseClient(HttpClient http, IOptions<CollectorOptions> options, ILogger<ClickHouseClient> logger)
{
    private ClickHouseOptions Opt => options.Value.ClickHouse;

    public async Task EnsureSchemaAsync(CancellationToken ct)
    {
        using var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream("schema.sql")
            ?? throw new InvalidOperationException("schema.sql não embutido");
        var sql = await new StreamReader(stream).ReadToEndAsync(ct);
        sql = sql.Replace("{db}", QuoteIdent(Opt.Database))
                 .Replace("{ttl_days}", Opt.TtlDays.ToString())
                 .Replace("{rollup_ttl_days}", Opt.RollupTtlDays.ToString());

        var statements = string.Join('\n', sql.Split('\n').Where(l => !l.TrimStart().StartsWith("--")))
            .Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
        foreach (var statement in statements)
            await ExecuteAsync(statement, null, null, ct);
        logger.LogInformation("Schema conferido em {Url}, banco {Db}", Opt.Url, Opt.Database);
    }

    /// <summary>
    /// Insere linhas JSONEachRow. O token torna o reenvio do mesmo lote idempotente (o ClickHouse descarta o bloco repetido).
    /// </summary>
    public Task<string> InsertAsync(string table, MemoryStream rows, string dedupToken, CancellationToken ct)
    {
        var query = $"INSERT INTO {QuoteIdent(Opt.Database)}.{QuoteIdent(table)} FORMAT JSONEachRow";
        return ExecuteAsync(query, rows, new Dictionary<string, string>
        {
            ["insert_deduplication_token"] = dedupToken,
            ["input_format_skip_unknown_fields"] = "1",
            ["date_time_input_format"] = "best_effort",
        }, ct);
    }

    /// <summary>
    /// Quantas requisições destes sites/servidores, nesta faixa de início (unix ns), existem na tabela requests.
    /// Usado antes de apagar um arquivo: confirma que o que foi enviado está mesmo lá.
    /// </summary>
    public async Task<long> CountRequestsAsync(IEnumerable<string> sites, IEnumerable<string> servers, long fromNanos, long toNanos, CancellationToken ct)
    {
        var query = $"SELECT count() FROM {QuoteIdent(Opt.Database)}.requests " +
                    "WHERE Site IN {sites:Array(String)} AND Server IN {servers:Array(String)} " +
                    "AND Timestamp BETWEEN fromUnixTimestamp64Micro({from:Int64}) AND fromUnixTimestamp64Micro({to:Int64}) " +
                    "FORMAT TabSeparated";
        var text = await ExecuteAsync(query, null, new Dictionary<string, string>
        {
            ["param_sites"] = ArrayLiteral(sites),
            ["param_servers"] = ArrayLiteral(servers),
            ["param_from"] = (fromNanos / 1000).ToString(System.Globalization.CultureInfo.InvariantCulture),
            ["param_to"] = (toNanos / 1000).ToString(System.Globalization.CultureInfo.InvariantCulture),
        }, ct);
        return long.Parse(text.Trim(), System.Globalization.CultureInfo.InvariantCulture);
    }

    private static string ArrayLiteral(IEnumerable<string> values) =>
        "[" + string.Join(',', values.Select(v => "'" + v.Replace("\\", "\\\\").Replace("'", "\\'") + "'")) + "]";

    private async Task<string> ExecuteAsync(string query, MemoryStream? body, Dictionary<string, string>? settings, CancellationToken ct)
    {
        var url = new UriBuilder(Opt.Url) { Path = "/" };
        var qs = new List<string>();
        if (body is not null) qs.Add("query=" + Uri.EscapeDataString(query));
        foreach (var (k, v) in settings ?? []) qs.Add($"{k}={Uri.EscapeDataString(v)}");
        url.Query = string.Join('&', qs);

        using var req = new HttpRequestMessage(HttpMethod.Post, url.Uri);
        req.Headers.Add("X-ClickHouse-User", Opt.User);
        var password = Environment.GetEnvironmentVariable("MONITOR_CLICKHOUSE_PASSWORD") ?? Opt.Password;
        if (password.Length > 0) req.Headers.Add("X-ClickHouse-Key", password);

        if (body is null)
        {
            req.Content = new StringContent(query);
        }
        else
        {
            var gz = new MemoryStream();
            using (var z = new GZipStream(gz, CompressionLevel.Fastest, leaveOpen: true))
                z.Write(body.GetBuffer(), 0, (int)body.Length);
            gz.Position = 0;
            req.Content = new StreamContent(gz);
            req.Content.Headers.ContentEncoding.Add("gzip");
            req.Content.Headers.ContentType = new MediaTypeHeaderValue("application/x-ndjson");
        }

        using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        cts.CancelAfter(TimeSpan.FromSeconds(Opt.TimeoutSeconds));
        using var resp = await http.SendAsync(req, cts.Token);
        if (!resp.IsSuccessStatusCode)
        {
            var text = await resp.Content.ReadAsStringAsync(ct);
            throw new ClickHouseException((int)resp.StatusCode, text.Length > 2000 ? text[..2000] : text);
        }
        return await resp.Content.ReadAsStringAsync(ct);
    }

    private static string QuoteIdent(string name) => "`" + name.Replace("\\", "\\\\").Replace("`", "\\`") + "`";
}

public sealed class ClickHouseException(int status, string message) : Exception($"ClickHouse HTTP {status}: {message.Trim()}")
{
    public int Status { get; } = status;
    /// <summary>4xx: erro nos dados ou na consulta; reenviar não resolve.</summary>
    public bool IsClientError => Status is >= 400 and < 500 && Status is not (401 or 403 or 408 or 429);
}
