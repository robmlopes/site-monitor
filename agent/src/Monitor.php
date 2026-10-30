<?php
/**
 * Estado da requisição e registro de chamadas a outros sistemas ("spans").
 * Ao fim da requisição, agent.php exporta tudo como um trace OpenTelemetry (OTLP/JSON).
 */
final class Monitor
{
    const VERSION = '2.0.0';

    public static $config = array();

    /** Início da requisição (null = agente desligado: toda instrumentação vira no-op). */
    public static $start;
    public static $site;
    public static $spans = array();
    public static $dropped = 0;
    /** Nome lógico da rota (ex.: "Pedido\Pedido::detalhe"), definido por integrações de framework. */
    public static $route;
    /** Exceção tratada pelo framework (que por isso não chega ao error_get_last). */
    public static $error;
    /** Callbacks executados antes de exportar (ex.: fechar spans pendentes). */
    public static $finalizers = array();

    // Contexto W3C Trace Context: https://www.w3.org/TR/trace-context/
    public static $traceId;
    public static $spanId;
    public static $parentSpanId;

    /** Cabeçalhos definidos com curl_setopt(CURLOPT_HTTPHEADER) por handle, para acrescentar o traceparent. */
    public static $curlHeaders = array();

    public static function config($key, $default = null)
    {
        return array_key_exists($key, self::$config) ? self::$config[$key] : $default;
    }

    public static function newId($bytes)
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Lê o traceparent recebido (chamada vinda de outro sistema monitorado) ou começa um trace novo. */
    public static function startTrace($traceparent)
    {
        if ($traceparent && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', trim($traceparent), $m)
            && $m[1] !== str_repeat('0', 32)) {
            self::$traceId = $m[1];
            self::$parentSpanId = $m[2];
        } else {
            self::$traceId = self::newId(16);
        }
        self::$spanId = self::newId(8);
    }

    /** Cabeçalho para uma chamada de saída; $spanId é o id do span que vai medi-la. */
    public static function traceparent($spanId)
    {
        return '00-' . self::$traceId . '-' . $spanId . '-01';
    }

    /**
     * Registra um span já medido. $startedAt = microtime(true) do início.
     * $detail: dados extras (status HTTP, tempos de DNS/TLS...). $spanId: quando já foi propagado.
     */
    public static function record($type, $target, $op, $startedAt, $ok = true, $error = null, $detail = null, $spanId = null)
    {
        if (self::$start === null) {
            return;
        }
        if (count(self::$spans) >= self::config('max_spans', 100)) {
            self::$dropped++;
            self::$droppedMs += (microtime(true) - $startedAt) * 1000;
            return;
        }
        $span = array(
            'id'     => $spanId ?: self::newId(8),
            'type'   => $type,
            'target' => self::utf8($target),
            'op'     => mb_substr(preg_replace('/\s+/', ' ', trim(self::utf8($op))), 0, 500, 'UTF-8'),
            'start'  => $startedAt,
            'end'    => microtime(true),
            'ok'     => (bool) $ok,
        );
        if ($error !== null) {
            $span['error'] = mb_substr(self::utf8($error), 0, 300, 'UTF-8');
        }
        if ($detail !== null) {
            $span['detail'] = $detail;
        }
        self::$spans[] = $span;
    }

    /** Tempo dos spans que passaram do limite e não foram guardados (entra no total externo). */
    public static $droppedMs = 0;

    /** Mede um bloco qualquer; exceções são registradas e relançadas. */
    public static function span($type, $target, $op, $fn)
    {
        $t = microtime(true);
        try {
            $result = $fn();
        } catch (Exception $e) {
            self::record($type, $target, $op, $t, false, $e->getMessage());
            throw $e;
        } catch (Error $e) {
            self::record($type, $target, $op, $t, false, $e->getMessage());
            throw $e;
        }
        self::record($type, $target, $op, $t);
        return $result;
    }

    /** Proxy que mede toda chamada de método de um cliente (Redis, Memcached, SDKs...). */
    public static function wrap($client, $type, $target)
    {
        return new MonitorProxy($client, $type, $target);
    }

    public static function finalize()
    {
        foreach (self::$finalizers as $fn) {
            $fn();
        }
        self::$finalizers = array();
    }

    /**
     * Troca valores literais do SQL por "?": agrupa consultas iguais e evita levar dados (CPF, nomes...) ao painel.
     * Ex.: "WHERE id = 123 AND nome = 'Ana'" -> "WHERE id = ? AND nome = ?"
     */
    public static function normalizeSql($sql)
    {
        $sql = preg_replace("/N?'(?:[^']|'')*'/", '?', (string) $sql);
        $sql = preg_replace('/(?<![\w@#$.])-?\d+(?:\.\d+)?\b/', '?', $sql);
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?, ...)', $sql);
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    /** Garante UTF-8 para o JSON (sites em ISO-8859-1 teriam esses textos trocados por null). */
    public static function utf8($s)
    {
        $s = (string) $s;
        return preg_match('//u', $s) ? $s : utf8_encode($s);
    }

    public static function hostOf($url)
    {
        $p = parse_url($url);
        if (empty($p['host'])) {
            return (string) $url;
        }
        return $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** URL sem query string e sem usuário/senha, para não vazar tokens no painel. */
    public static function safeUrl($url)
    {
        $p = parse_url($url);
        if (empty($p['host'])) {
            return (string) $url;
        }
        return (isset($p['scheme']) ? $p['scheme'] . '://' : '') . self::hostOf($url) . (isset($p['path']) ? $p['path'] : '');
    }

    /** Pasta de dados deste site. */
    public static function siteDir()
    {
        return rtrim(self::config('data_dir'), '\\/') . '/' . preg_replace('/[^a-z0-9._-]/i', '_', self::$site);
    }
}

final class MonitorProxy
{
    private $client;
    private $type;
    private $target;

    public function __construct($client, $type, $target)
    {
        $this->client = $client;
        $this->type = $type;
        $this->target = $target;
    }

    public function __call($method, $args)
    {
        $client = $this->client;
        return Monitor::span($this->type, $this->target, $method, function () use ($client, $method, $args) {
            return call_user_func_array(array($client, $method), $args);
        });
    }

    public function __get($name)
    {
        return $this->client->$name;
    }
}
