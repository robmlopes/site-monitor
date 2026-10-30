<?php
/**
 * Instrumentação de chamadas a outros sistemas (PHP 7.0+).
 * Cada chamada vira um "span": tipo, sistema de destino, operação, quando começou e quanto demorou.
 *
 * Como usar no site:
 *   Banco (PDO):   new MonitorPDO($dsn, $user, $pass)          no lugar de new PDO(...)
 *   Banco (mysqli): new MonitorMysqli($host, $user, $pass, $db) no lugar de new mysqli(...)
 *   cURL:          monitor_curl_exec($ch)                      no lugar de curl_exec($ch)
 *   SOAP:          new MonitorSoapClient($wsdl, $options)      no lugar de new SoapClient(...)
 *   Redis/outros:  $redis = Monitor::wrap($redis, 'redis', 'host:6379')
 *   Qualquer bloco: Monitor::span('fila', 'rabbitmq', 'publicar pedido', function () { ... })
 *   HTTP via file_get_contents/fopen: automático se MONITOR_AUTO_HTTP estiver ligado (ver agent.php).
 */

final class Monitor
{
    const MAX_SPANS = 100;

    public static $start;
    public static $spans = array();
    public static $dropped = 0;

    /** Registra um span já medido. $startedAt = microtime(true) do início. */
    public static function record($type, $target, $op, $startedAt, $ok = true, $error = null, $detail = null)
    {
        if (self::$start === null) {
            return; // agente não carregado: instrumentação vira no-op
        }
        if (count(self::$spans) >= self::MAX_SPANS) {
            self::$dropped++;
            return;
        }
        $span = array(
            'type'   => $type,
            'target' => (string) $target,
            'op'     => mb_substr(preg_replace('/\s+/', ' ', trim((string) $op)), 0, 200),
            'at'     => round(($startedAt - self::$start) * 1000, 2),
            'ms'     => round((microtime(true) - $startedAt) * 1000, 2),
            'ok'     => (bool) $ok,
        );
        if ($error !== null) {
            $span['error'] = mb_substr((string) $error, 0, 200);
        }
        if ($detail !== null) {
            $span['detail'] = $detail;
        }
        self::$spans[] = $span;
    }

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

// ---------- Banco de dados ----------

class MonitorPDO extends PDO
{
    public $monitorTarget;

    public function __construct($dsn, $username = null, $password = null, $options = array())
    {
        $this->monitorTarget = self::describeDsn($dsn);
        $t = microtime(true);
        try {
            parent::__construct($dsn, $username, $password, (array) $options);
        } catch (PDOException $e) {
            Monitor::record('db', $this->monitorTarget, 'connect', $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->monitorTarget, 'connect', $t);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('MonitorPDOStatement', array($this)));
    }

    /** "mysql 127.0.0.1/loja" — nunca inclui usuário/senha. */
    public static function describeDsn($dsn)
    {
        $driver = strtok($dsn, ':');
        $rest = (string) substr($dsn, strlen($driver) + 1);
        if ($driver === 'sqlite') {
            return 'sqlite ' . basename($rest);
        }
        $host = preg_match('/(?:host|server)=([^;]+)/i', $rest, $m) ? $m[1] : '?';
        $db = preg_match('/(?:dbname|database)=([^;]+)/i', $rest, $m) ? '/' . $m[1] : '';
        return "$driver $host$db";
    }

    public function query($statement)
    {
        $args = func_get_args();
        $t = microtime(true);
        try {
            $r = parent::query(...$args);
        } catch (PDOException $e) {
            Monitor::record('db', $this->monitorTarget, $statement, $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->monitorTarget, $statement, $t, $r !== false, $r === false ? $this->errorInfo()[2] : null);
        return $r;
    }

    public function exec($statement)
    {
        $t = microtime(true);
        try {
            $r = parent::exec($statement);
        } catch (PDOException $e) {
            Monitor::record('db', $this->monitorTarget, $statement, $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->monitorTarget, $statement, $t, $r !== false);
        return $r;
    }
}

class MonitorPDOStatement extends PDOStatement
{
    private $pdo;

    protected function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute($params = null)
    {
        $t = microtime(true);
        try {
            $r = parent::execute($params);
        } catch (PDOException $e) {
            Monitor::record('db', $this->pdo->monitorTarget, $this->queryString, $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->pdo->monitorTarget, $this->queryString, $t, $r, $r ? null : $this->errorInfo()[2]);
        return $r;
    }
}

class MonitorMysqli extends mysqli
{
    private $monitorTarget;

    public function __construct($host = null, $user = null, $pass = null, $db = '', $port = null, $socket = null)
    {
        $host = $host === null ? ini_get('mysqli.default_host') : $host;
        $this->monitorTarget = 'mysql ' . $host . ($db ? "/$db" : '');
        $t = microtime(true);
        @parent::__construct($host, $user, $pass, $db, $port === null ? (int) ini_get('mysqli.default_port') : $port, $socket);
        Monitor::record('db', $this->monitorTarget, 'connect', $t, !$this->connect_errno, $this->connect_errno ? $this->connect_error : null);
    }

    public function query($query, $resultmode = MYSQLI_STORE_RESULT)
    {
        $t = microtime(true);
        $r = parent::query($query, $resultmode);
        Monitor::record('db', $this->monitorTarget, $query, $t, $r !== false, $r === false ? $this->error : null);
        return $r;
    }
}

// ---------- HTTP ----------

/** Substituto de curl_exec() com tempos detalhados (DNS, conexão, TLS, primeiro byte, total). */
function monitor_curl_exec($ch)
{
    $t = microtime(true);
    $result = curl_exec($ch);
    $info = curl_getinfo($ch);
    $errno = curl_errno($ch);
    $code = (int) $info['http_code'];

    $error = null;
    if ($errno) {
        $error = curl_error($ch);
    } elseif ($code >= 500 || $code === 0) {
        $error = "HTTP $code";
    }
    // O método só é conhecido se o site ligou CURLINFO_HEADER_OUT.
    $method = '';
    if (isset($info['request_header']) && preg_match('/^([A-Z]+) /', $info['request_header'], $m)) {
        $method = $m[1] . ' ';
    }

    Monitor::record('http', Monitor::hostOf($info['url']), $method . Monitor::safeUrl($info['url']), $t, $error === null, $error, array(
        'status'  => $code,
        'dns'     => round($info['namelookup_time'] * 1000, 2),
        'connect' => round($info['connect_time'] * 1000, 2),
        'tls'     => round(max(0, $info['pretransfer_time'] - $info['connect_time']) * 1000, 2),
        'ttfb'    => round($info['starttransfer_time'] * 1000, 2),
        'total'   => round($info['total_time'] * 1000, 2),
        'bytes'   => (int) $info['size_download'],
    ));
    return $result;
}

class MonitorSoapClient extends SoapClient
{
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        $t = microtime(true);
        try {
            $r = parent::__doRequest($request, $location, $action, $version, $one_way);
        } catch (SoapFault $e) {
            Monitor::record('soap', Monitor::hostOf($location), $action, $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('soap', Monitor::hostOf($location), $action ? $action : Monitor::safeUrl($location), $t, $r !== null, $r === null ? 'sem resposta' : null);
        return $r;
    }
}

/**
 * Wrapper dos protocolos http/https: mede file_get_contents(), fopen() etc. sem mudar o código do site.
 * Atenção: com ele ligado, a variável mágica $http_response_header não é preenchida no código do site.
 */
class MonitorHttpStream
{
    public $context;
    private $fp;
    private $url;
    private $started;
    private $ttfb;
    private $status = 0;
    private $bytes = 0;

    public static function enable()
    {
        foreach (array('http', 'https') as $proto) {
            if (in_array($proto, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($proto);
                stream_wrapper_register($proto, __CLASS__, STREAM_IS_URL);
            }
        }
    }

    private static function withOriginal($fn)
    {
        stream_wrapper_restore('http');
        if (in_array('https', stream_get_wrappers(), true)) {
            stream_wrapper_restore('https');
        }
        try {
            return $fn();
        } finally {
            self::enable();
        }
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->url = $path;
        $this->started = microtime(true);
        $ctx = $this->context;
        $this->fp = self::withOriginal(function () use ($path, $mode, $ctx) {
            return $ctx ? @fopen($path, $mode, false, $ctx) : @fopen($path, $mode);
        });
        $this->ttfb = round((microtime(true) - $this->started) * 1000, 2);

        if (!$this->fp) {
            $err = error_get_last();
            Monitor::record('http', Monitor::hostOf($path), 'GET ' . Monitor::safeUrl($path), $this->started, false,
                $err ? preg_replace('/^.*?: /', '', $err['message']) : 'falha ao abrir', array('ttfb' => $this->ttfb));
            return false;
        }
        $meta = stream_get_meta_data($this->fp);
        foreach ((array) $meta['wrapper_data'] as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $this->status = (int) $m[1];
            }
        }
        return true;
    }

    public function stream_read($count)
    {
        $data = fread($this->fp, $count);
        $this->bytes += strlen($data);
        return $data;
    }

    public function stream_write($data) { return fwrite($this->fp, $data); }
    public function stream_eof() { return feof($this->fp); }
    public function stream_stat() { return fstat($this->fp); }
    public function stream_tell() { return ftell($this->fp); }
    public function stream_seek($offset, $whence) { return fseek($this->fp, $offset, $whence) === 0; }
    public function stream_set_option($option, $arg1, $arg2) { return false; }

    public function stream_close()
    {
        fclose($this->fp);
        $ok = $this->status > 0 && $this->status < 500;
        Monitor::record('http', Monitor::hostOf($this->url), 'GET ' . Monitor::safeUrl($this->url), $this->started, $ok,
            $ok ? null : "HTTP {$this->status}", array(
                'status' => $this->status,
                'ttfb'   => $this->ttfb,
                'total'  => round((microtime(true) - $this->started) * 1000, 2),
                'bytes'  => $this->bytes,
            ));
    }

    public function url_stat($path, $flags)
    {
        return false;
    }
}
