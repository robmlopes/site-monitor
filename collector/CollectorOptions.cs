namespace Monitor.Collector;

public sealed class CollectorOptions
{
    /// <summary>Pasta de dados do agente: &lt;DataDir&gt;/&lt;site&gt;/traces-AAAAMMDD-HH.jsonl. Relativa à pasta do coletor.</summary>
    public string DataDir { get; set; } = @"..\data";
    /// <summary>Onde guardar até onde cada arquivo já foi enviado. Padrão: &lt;DataDir&gt;/collector-state.json.</summary>
    public string? StateFile { get; set; }
    public int PollSeconds { get; set; } = 2;
    /// <summary>Limites de um lote (um INSERT por tabela).</summary>
    public int BatchMaxLines { get; set; } = 5000;
    public int BatchMaxBytes { get; set; } = 8 * 1024 * 1024;
    /// <summary>Na primeira execução (sem estado), começa no fim dos arquivos existentes em vez de enviar o histórico.</summary>
    public bool StartAtEnd { get; set; }
    /// <summary>
    /// Apaga o arquivo depois de todo enviado e conferido no ClickHouse. No modo ClickHouse ninguém chama
    /// /_monitor/traces.php (que é quem aplica o retention_hours do agente), então sem isto os arquivos só crescem.
    /// </summary>
    public bool DeleteShipped { get; set; } = true;
    /// <summary>
    /// Só apaga arquivos sem gravação há este tempo. Acima de 60 min a hora do arquivo já fechou: o agente não
    /// escreve mais nele, então não há risco de apagar uma linha gravada entre a conferência e a exclusão.
    /// </summary>
    public int DeleteAfterMinutes { get; set; } = 70;
    /// <summary>Arquivos sem contagem do que foi enviado (estado antigo, StartAtEnd) são apagados só depois disto, como o retention_hours do agente.</summary>
    public int UnverifiedRetentionHours { get; set; } = 48;
    public ClickHouseOptions ClickHouse { get; set; } = new();
}

public sealed class ClickHouseOptions
{
    /// <summary>Interface HTTP do ClickHouse, ex.: http://clickhouse:8123 ou https://xxx.clickhouse.cloud:8443</summary>
    public string Url { get; set; } = "http://localhost:8123";
    public string Database { get; set; } = "monitor";
    public string User { get; set; } = "default";
    /// <summary>Também pode vir da variável de ambiente MONITOR_CLICKHOUSE_PASSWORD.</summary>
    public string Password { get; set; } = "";
    public int TimeoutSeconds { get; set; } = 30;
    public bool IgnoreTlsErrors { get; set; }
    /// <summary>Cria banco, tabelas e views (schema.sql) ao iniciar. Desligue se o usuário não tiver permissão de DDL.</summary>
    public bool CreateSchema { get; set; } = true;
    public int TtlDays { get; set; } = 30;
    public int RollupTtlDays { get; set; } = 400;
}
