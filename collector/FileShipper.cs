using System.Text.Json;
using System.Text.Json.Nodes;
using Microsoft.Extensions.Options;

namespace Monitor.Collector;

/// <summary>
/// Acompanha os arquivos &lt;DataDir&gt;/&lt;site&gt;/traces-*.jsonl gravados pelo agente e envia as linhas novas ao ClickHouse.
/// Guarda o offset de cada arquivo só depois do INSERT confirmado: se o ClickHouse cair, os próprios arquivos são o
/// buffer. Reenvios do mesmo trecho são descartados pelo token de deduplicação.
/// Arquivo todo enviado, com a hora fechada e conferido no ClickHouse (contagem de requisições) é apagado.
/// </summary>
public sealed class FileShipper(
    IOptions<CollectorOptions> options, ClickHouseClient clickHouse, IHostEnvironment env,
    ILogger<FileShipper> logger) : BackgroundService
{
    private static readonly TimeSpan MaxBackoff = TimeSpan.FromMinutes(5);
    private static readonly JsonSerializerOptions StateJson = new() { WriteIndented = true };
    private readonly Dictionary<string, FileState> _files = new(StringComparer.OrdinalIgnoreCase);
    /// <summary>Arquivo que não pôde ser apagado (conferência falhou): só tenta de novo depois deste horário.</summary>
    private readonly Dictionary<string, DateTime> _retryDeleteAt = new(StringComparer.OrdinalIgnoreCase);
    private string _dataDir = "";
    private string _stateFile = "";
    private long _sentRequests, _sentCalls, _badLines, _deleted;
    private DateTime _lastStats = DateTime.UtcNow;

    private CollectorOptions Opt => options.Value;

    /// <summary>Até onde o arquivo foi enviado e o que foi enviado dele (para conferir antes de apagar).</summary>
    public sealed class FileState
    {
        public long Offset { get; set; }
        /// <summary>Requisições aceitas pelo ClickHouse. null: estado antigo, sem contagem.</summary>
        public long? Requests { get; set; } = 0;
        /// <summary>Requisições de lotes recusados (salvos em rejected/), que não estarão no ClickHouse.</summary>
        public long Rejected { get; set; }
        public long MinStart { get; set; } = long.MaxValue;
        public long MaxStart { get; set; } = long.MinValue;
        public HashSet<string> Sites { get; set; } = [];
        public HashSet<string> Servers { get; set; } = [];
    }

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        _dataDir = Path.GetFullPath(Path.Combine(env.ContentRootPath, Opt.DataDir));
        _stateFile = Opt.StateFile is { Length: > 0 } sf
            ? Path.GetFullPath(Path.Combine(env.ContentRootPath, sf))
            : Path.Combine(_dataDir, "collector-state.json");
        logger.LogInformation("Lendo {DataDir} e enviando para {Url} (estado em {State}; apagar enviados: {Delete})",
            _dataDir, Opt.ClickHouse.Url, _stateFile, Opt.DeleteShipped ? $"após {DeleteAfter.TotalMinutes} min sem gravação" : "não");

        LoadState();

        var backoff = TimeSpan.FromSeconds(5);
        var schemaOk = !Opt.ClickHouse.CreateSchema;
        while (!ct.IsCancellationRequested)
        {
            try
            {
                if (!schemaOk)
                {
                    await clickHouse.EnsureSchemaAsync(ct);
                    schemaOk = true;
                }
                await ShipAllAsync(ct);
                backoff = TimeSpan.FromSeconds(5);
                LogStats();
                await Task.Delay(TimeSpan.FromSeconds(Opt.PollSeconds), ct);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogWarning("Falha ao enviar ({Error}); nova tentativa em {Seconds}s", ex.Message, (int)backoff.TotalSeconds);
                await Task.Delay(backoff, ct).ContinueWith(_ => { }, CancellationToken.None);
                backoff = TimeSpan.FromTicks(Math.Min(backoff.Ticks * 2, MaxBackoff.Ticks));
            }
        }
    }

    /// <summary>Nunca menos de 61 min: antes disso o agente ainda pode gravar no arquivo da hora.</summary>
    private TimeSpan DeleteAfter => TimeSpan.FromMinutes(Math.Max(61, Opt.DeleteAfterMinutes));

    private async Task ShipAllAsync(CancellationToken ct)
    {
        if (!Directory.Exists(_dataDir)) return;

        var files = Directory.EnumerateDirectories(_dataDir)
            .SelectMany(d => Directory.EnumerateFiles(d, "traces-*.jsonl"))
            .Order(StringComparer.OrdinalIgnoreCase)
            .ToList();

        var known = files.Select(Key).ToHashSet(StringComparer.OrdinalIgnoreCase);
        var removed = _files.Keys.Where(k => !known.Contains(k)).ToList();
        foreach (var k in removed) _files.Remove(k); // apagado por fora (retenção do agente, manual)
        if (removed.Count > 0) SaveState();

        foreach (var file in files)
        {
            try
            {
                // Esvazia o arquivo antes de passar ao próximo (ordem cronológica por site).
                while (await ShipChunkAsync(file, ct)) { }
                if (Opt.DeleteShipped) await DeleteIfDoneAsync(file, ct);
            }
            catch (IOException ex) when (ex is not FileNotFoundException and not DirectoryNotFoundException)
            {
                // O agente grava com LOCK_EX: leitura no mesmo instante falha; tenta no próximo ciclo.
                logger.LogDebug("{File} ocupado ({Error}); tenta no próximo ciclo", Key(file), ex.Message);
            }
            catch (Exception ex) when (ex is FileNotFoundException or DirectoryNotFoundException)
            {
                _files.Remove(Key(file));
            }
        }
    }

    /// <summary>Envia um lote do arquivo. Devolve true se ainda há linhas completas para enviar.</summary>
    private async Task<bool> ShipChunkAsync(string file, CancellationToken ct)
    {
        var key = Key(file);
        var site = Path.GetFileName(Path.GetDirectoryName(file))!;
        if (!_files.TryGetValue(key, out var state)) state = new FileState();
        var offset = state.Offset;

        byte[] buffer;
        int read;
        await using (var fs = new FileStream(file, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
        {
            if (fs.Length < offset)
            {
                logger.LogWarning("{File} diminuiu (recriado?); relendo do início", key);
                state = new FileState();
                offset = 0;
            }
            if (fs.Length == offset)
            {
                _files[key] = state;
                return false;
            }

            buffer = new byte[(int)Math.Min(Opt.BatchMaxBytes, fs.Length - offset)];
            fs.Seek(offset, SeekOrigin.Begin);
            read = await fs.ReadAtLeastAsync(buffer, buffer.Length, throwOnEndOfStream: false, ct);
        }

        var data = buffer.AsSpan(0, read);
        using var batch = new Batch();
        var consumed = 0;
        var lines = 0;
        while (lines < Opt.BatchMaxLines)
        {
            var nl = data[consumed..].IndexOf((byte)'\n');
            if (nl < 0) break; // linha ainda sendo escrita
            var line = data.Slice(consumed, nl).TrimEnd((byte)'\r');
            consumed += nl + 1;
            if (line.IsEmpty) continue;
            lines++;
            TraceMapper.Append(batch, line, site, Environment.MachineName);
        }

        if (consumed == 0)
        {
            if (read < Opt.BatchMaxBytes) return false; // espera o resto da linha
            logger.LogWarning("{File}: linha maior que {Max} bytes no offset {Offset}; descartada", key, Opt.BatchMaxBytes, offset);
            consumed = read;
        }

        batch.Flush();
        var end = offset + consumed;
        var token = $"{key}:{offset}-{end}";
        var requestsOk = await InsertAsync("requests", batch.Requests, token, file, ct);
        await InsertAsync("calls", batch.Calls, token, file, ct);

        if (batch.BadLines > 0)
            logger.LogWarning("{File}: {Count} linha(s) inválida(s) ignorada(s) entre {Start} e {End}", key, batch.BadLines, offset, end);

        if (batch.RequestCount > 0)
        {
            if (requestsOk) state.Requests += batch.RequestCount;
            else state.Rejected += batch.RequestCount;
            state.MinStart = Math.Min(state.MinStart, batch.MinStart);
            state.MaxStart = Math.Max(state.MaxStart, batch.MaxStart);
            state.Sites.UnionWith(batch.Sites);
            state.Servers.UnionWith(batch.Servers);
        }
        state.Offset = end;
        _files[key] = state;
        SaveState();

        _sentRequests += batch.RequestCount;
        _sentCalls += batch.CallCount;
        _badLines += batch.BadLines;
        return consumed < read || lines >= Opt.BatchMaxLines || read == Opt.BatchMaxBytes;
    }

    /// <summary>
    /// Apaga o arquivo se: foi todo enviado, não é gravado há DeleteAfter (a hora dele fechou) e o ClickHouse tem
    /// pelo menos as requisições que foram aceitas dele. Qualquer dúvida: mantém o arquivo e avisa.
    /// </summary>
    private async Task DeleteIfDoneAsync(string file, CancellationToken ct)
    {
        var key = Key(file);
        if (!_files.TryGetValue(key, out var state)) return;

        var info = new FileInfo(file);
        if (!info.Exists || info.Length != state.Offset) return;                    // ainda há o que enviar
        var idle = DateTime.UtcNow - info.LastWriteTimeUtc;
        if (idle < DeleteAfter) return;                                             // hora ainda aberta
        if (_retryDeleteAt.TryGetValue(key, out var retry) && DateTime.UtcNow < retry) return;

        if (state.Requests is null)
        {
            // Sem contagem (estado antigo ou StartAtEnd): não dá para conferir; segue a retenção do agente.
            if (idle < TimeSpan.FromHours(Math.Max(1, Opt.UnverifiedRetentionHours)))
            {
                if (!_retryDeleteAt.ContainsKey(key))
                    logger.LogWarning("{File}: sem contagem do que foi enviado; será apagado só após {Hours} h (retenção)",
                        key, Opt.UnverifiedRetentionHours);
                _retryDeleteAt[key] = DateTime.UtcNow.AddMinutes(30);
                return;
            }
        }
        else if (state.Requests > 0)
        {
            var found = await clickHouse.CountRequestsAsync(state.Sites, state.Servers, state.MinStart, state.MaxStart, ct);
            if (found < state.Requests)
            {
                logger.LogWarning("{File}: ClickHouse tem {Found} de {Sent} requisições enviadas; arquivo mantido (nova conferência em 10 min)",
                    key, found, state.Requests);
                _retryDeleteAt[key] = DateTime.UtcNow.AddMinutes(10);
                return;
            }
        }

        File.Delete(file);
        _files.Remove(key);
        _retryDeleteAt.Remove(key);
        SaveState();
        _deleted++;
        logger.LogInformation("{File} apagado: {Result}{Rejected}", key,
            state.Requests is null ? "sem conferência (passou da retenção)" : $"{state.Requests} requisição(ões) conferidas no ClickHouse",
            state.Rejected > 0 ? $", {state.Rejected} em rejected/" : "");
    }

    /// <summary>Envia ao ClickHouse. Devolve false se o lote foi recusado (e salvo em rejected/).</summary>
    private async Task<bool> InsertAsync(string table, MemoryStream rows, string token, string file, CancellationToken ct)
    {
        if (rows.Length == 0) return true;
        try
        {
            await clickHouse.InsertAsync(table, rows, $"{table}:{token}", ct);
            return true;
        }
        catch (ClickHouseException ex) when (ex.IsClientError)
        {
            // Dado que o ClickHouse recusa (tipo, formato): reenviar travaria o arquivo para sempre.
            // Guarda o lote em <DataDir>/rejected/ para análise e segue.
            var dir = Path.Combine(_dataDir, "rejected");
            Directory.CreateDirectory(dir);
            var name = $"{table}-{DateTime.UtcNow:yyyyMMdd-HHmmss}-{Path.GetFileNameWithoutExtension(file)}.jsonl";
            await File.WriteAllBytesAsync(Path.Combine(dir, name), rows.ToArray(), ct);
            logger.LogError("Lote recusado pelo ClickHouse ({Error}); salvo em {Path}", ex.Message, Path.Combine(dir, name));
            return false;
        }
    }

    private string Key(string file) => Path.GetRelativePath(_dataDir, file).Replace('\\', '/');

    private void LoadState()
    {
        if (File.Exists(_stateFile))
        {
            try
            {
                var root = JsonNode.Parse(File.ReadAllText(_stateFile))?.AsObject() ?? [];
                foreach (var (k, v) in root)
                {
                    // Formato antigo: só o offset ("arquivo": 1234).
                    _files[k] = v is JsonValue n && n.TryGetValue<long>(out var offset)
                        ? new FileState { Offset = offset, Requests = null }
                        : v.Deserialize<FileState>() ?? new FileState();
                }
                logger.LogInformation("Estado carregado: {Count} arquivo(s)", _files.Count);
                return;
            }
            catch (Exception ex) when (ex is JsonException or IOException or InvalidOperationException)
            {
                logger.LogWarning("Estado ilegível em {State} ({Error}); começando de novo", _stateFile, ex.Message);
            }
        }

        if (Opt.StartAtEnd && Directory.Exists(_dataDir))
        {
            foreach (var f in Directory.EnumerateDirectories(_dataDir).SelectMany(d => Directory.EnumerateFiles(d, "traces-*.jsonl")))
                _files[Key(f)] = new FileState { Offset = new FileInfo(f).Length, Requests = null };
            logger.LogInformation("Primeira execução com StartAtEnd: ignorando o histórico de {Count} arquivo(s)", _files.Count);
        }
    }

    private void SaveState()
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_stateFile)!);
        var tmp = _stateFile + ".tmp";
        File.WriteAllText(tmp, JsonSerializer.Serialize(_files, StateJson));
        File.Move(tmp, _stateFile, overwrite: true);
    }

    private void LogStats()
    {
        if (DateTime.UtcNow - _lastStats < TimeSpan.FromMinutes(1)) return;
        _lastStats = DateTime.UtcNow;
        if (_sentRequests + _sentCalls + _badLines + _deleted == 0) return;
        logger.LogInformation("Último minuto: {Requests} requisições, {Calls} chamadas, {Bad} linhas inválidas, {Deleted} arquivo(s) apagado(s)",
            _sentRequests, _sentCalls, _badLines, _deleted);
        _sentRequests = _sentCalls = _badLines = _deleted = 0;
    }
}
