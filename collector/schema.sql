-- Schema do monitor no ClickHouse. O coletor executa este arquivo ao iniciar (CreateSchema = true);
-- também pode ser rodado à mão trocando {db} pelo nome do banco e {ttl_days} pela retenção.
-- Uma linha por requisição (span SERVER) em "requests" e uma por chamada filha (span CLIENT) em "calls",
-- com a rota da requisição repetida na chamada para agregar sem JOIN.
-- non_replicated_deduplication_window: reenvios do mesmo trecho de arquivo (insert_deduplication_token) são ignorados.

CREATE DATABASE IF NOT EXISTS {db};

CREATE TABLE IF NOT EXISTS {db}.requests
(
    Timestamp          DateTime64(6, 'UTC') CODEC(Delta, ZSTD(1)),
    Site               LowCardinality(String),
    Server             LowCardinality(String),
    TraceId            String CODEC(ZSTD(1)),
    SpanId             String CODEC(ZSTD(1)),
    ParentSpanId       String CODEC(ZSTD(1)),
    Method             LowCardinality(String),
    Route              LowCardinality(String),
    Path               String CODEC(ZSTD(1)),
    Query              String CODEC(ZSTD(1)),
    Status             UInt16,
    DurationMs         Float64,
    CpuMs              Float32,
    CpuUserMs          Float32,
    CpuSysMs           Float32,
    MemPeak            UInt64,
    MemReserved        UInt64,
    ExtMs              Float64,
    ExtCalls           UInt32,
    Detailed           Bool,
    SpansDropped       UInt32,
    IsError            Bool,
    Error              String CODEC(ZSTD(1)),
    ClientIp           String CODEC(ZSTD(1)),
    UserAgent          String CODEC(ZSTD(1)),
    Referer            String CODEC(ZSTD(1)),
    Attributes         Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    INDEX idx_trace TraceId TYPE bloom_filter(0.001) GRANULARITY 1,
    INDEX idx_status Status TYPE minmax GRANULARITY 1,
    INDEX idx_duration DurationMs TYPE minmax GRANULARITY 1
)
ENGINE = MergeTree
PARTITION BY toYYYYMMDD(Timestamp)
ORDER BY (Site, Route, Timestamp)
TTL toDateTime(Timestamp) + toIntervalDay({ttl_days})
SETTINGS ttl_only_drop_parts = 1, non_replicated_deduplication_window = 10000;

CREATE TABLE IF NOT EXISTS {db}.calls
(
    Timestamp     DateTime64(6, 'UTC') CODEC(Delta, ZSTD(1)),
    Site          LowCardinality(String),
    Server        LowCardinality(String),
    TraceId       String CODEC(ZSTD(1)),
    SpanId        String CODEC(ZSTD(1)),
    ParentSpanId  String CODEC(ZSTD(1)),
    Method        LowCardinality(String),
    Route         LowCardinality(String),
    Type          LowCardinality(String),
    Target        LowCardinality(String),
    Op            String CODEC(ZSTD(1)),
    Name          String CODEC(ZSTD(1)),
    OffsetMs      Float64,
    DurationMs    Float64,
    Ok            Bool,
    Error         String CODEC(ZSTD(1)),
    HttpStatus    UInt16,
    DnsMs         Nullable(Float32),
    ConnectMs     Nullable(Float32),
    TlsMs         Nullable(Float32),
    TtfbMs        Nullable(Float32),
    Bytes         Nullable(UInt64),
    Attributes    Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    INDEX idx_trace TraceId TYPE bloom_filter(0.001) GRANULARITY 1,
    INDEX idx_duration DurationMs TYPE minmax GRANULARITY 1
)
ENGINE = MergeTree
PARTITION BY toYYYYMMDD(Timestamp)
ORDER BY (Site, Type, Target, Timestamp)
TTL toDateTime(Timestamp) + toIntervalDay({ttl_days})
SETTINGS ttl_only_drop_parts = 1, non_replicated_deduplication_window = 10000;

-- Agregado por minuto para gráficos de longo prazo (latência, erros, CPU por rota).
CREATE TABLE IF NOT EXISTS {db}.routes_1m
(
    Minute       DateTime('UTC'),
    Site         LowCardinality(String),
    Server       LowCardinality(String),
    Method       LowCardinality(String),
    Route        LowCardinality(String),
    Requests     UInt64,
    Errors       UInt64,
    Slow         UInt64,
    DurationSum  Float64,
    DurationMax  SimpleAggregateFunction(max, Float64),
    CpuSum       Float64,
    ExtSum       Float64,
    Quantiles    AggregateFunction(quantilesTDigest(0.5, 0.95, 0.99), Float64)
)
ENGINE = AggregatingMergeTree
PARTITION BY toYYYYMM(Minute)
ORDER BY (Site, Route, Method, Server, Minute)
TTL Minute + toIntervalDay({rollup_ttl_days});

CREATE MATERIALIZED VIEW IF NOT EXISTS {db}.routes_1m_mv TO {db}.routes_1m AS
SELECT
    toStartOfMinute(Timestamp) AS Minute,
    Site, Server, Method, Route,
    count() AS Requests,
    countIf(IsError OR Status >= 500) AS Errors,
    countIf(DurationMs >= 1000) AS Slow,
    sum(DurationMs) AS DurationSum,
    max(DurationMs) AS DurationMax,
    sum(CpuMs) AS CpuSum,
    sum(ExtMs) AS ExtSum,
    quantilesTDigestState(0.5, 0.95, 0.99)(DurationMs) AS Quantiles
FROM {db}.requests
GROUP BY Minute, Site, Server, Method, Route;
