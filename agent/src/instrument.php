<?php
/**
 * Medição de chamadas a outros sistemas (PHP 7.0+). Cada chamada vira um span em Monitor::$spans.
 *
 * Sem editar o site (via shims/integrações): curl_exec, curl_setopt, session_start, Zend\Http\Client,
 * Doctrine DBAL, Zend\Cache, file_get_contents/fopen em URL.
 * Editando uma linha no site, quando não há outro jeito:
 *   new MonitorPDO(...) / new MonitorMysqli(...) / new MonitorSoapClient(...)
 *   Monitor::wrap($cliente, 'redis', 'host:6379') / Monitor::span('fila', 'rabbitmq', 'publicar', function () {...})
 */

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
            Monitor::record('db', $this->monitorTarget, Monitor::normalizeSql($statement), $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->monitorTarget, Monitor::normalizeSql($statement), $t, $r !== false, $r === false ? $this->errorInfo()[2] : null);
        return $r;
    }

    public function exec($statement)
    {
        $t = microtime(true);
        try {
            $r = parent::exec($statement);
        } catch (PDOException $e) {
            Monitor::record('db', $this->monitorTarget, Monitor::normalizeSql($statement), $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->monitorTarget, Monitor::normalizeSql($statement), $t, $r !== false);
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
            Monitor::record('db', $this->pdo->monitorTarget, Monitor::normalizeSql($this->queryString), $t, false, $e->getMessage());
            throw $e;
        }
        Monitor::record('db', $this->pdo->monitorTarget, Monitor::normalizeSql($this->queryString), $t, $r, $r ? null : $this->errorInfo()[2]);
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
        Monitor::record('db', $this->monitorTarget, Monitor::normalizeSql($query), $t, $r !== false, $r === false ? $this->error : null);
        return $r;
    }
}

// ---------- HTTP ----------

/**
 * Substituto de curl_exec() com tempos detalhados (DNS, conexão, TLS, primeiro byte, total).
 * Se o site definiu cabeçalhos por curl_setopt (capturados pelos shims), acrescenta o traceparent.
 */
function monitor_curl_exec($ch)
{
    $spanId = Monitor::newId(8);
    $key = (int) $ch;
    if (Monitor::$start !== null && Monitor::config('propagate') && isset(Monitor::$curlHeaders[$key])) {
        $headers = array_filter(Monitor::$curlHeaders[$key], function ($h) {
            return stripos($h, 'traceparent:') !== 0;
        });
        $headers[] = 'traceparent: ' . Monitor::traceparent($spanId);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values($headers));
    }
    curl_setopt($ch, CURLINFO_HEADER_OUT, true); // só para saber o método (GET/POST...) no curl_getinfo
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
    $method = 'GET';
    if (isset($info['request_header']) && preg_match('/^([A-Z]+) /', $info['request_header'], $m)) {
        $method = $m[1];
    }

    Monitor::record('http', Monitor::hostOf($info['url']), $method . ' ' . Monitor::safeUrl($info['url']), $t, $error === null, $error, array(
        'method'  => $method,
        'url'     => Monitor::safeUrl($info['url']),
        'status'  => $code,
        'dns'     => round($info['namelookup_time'] * 1000, 2),
        'connect' => round($info['connect_time'] * 1000, 2),
        'tls'     => round(max(0, $info['pretransfer_time'] - $info['connect_time']) * 1000, 2),
        'ttfb'    => round($info['starttransfer_time'] * 1000, 2),
        'total'   => round($info['total_time'] * 1000, 2),
        'bytes'   => (int) $info['size_download'],
        'client'  => 'curl',
    ), $spanId);
    return $result;
}

/** curl_setopt() via shim: guarda os cabeçalhos do handle para poder acrescentar o traceparent. */
function monitor_curl_setopt($ch, $option, $value)
{
    if ($option === CURLOPT_HTTPHEADER) {
        Monitor::$curlHeaders[(int) $ch] = (array) $value;
    }
    return curl_setopt($ch, $option, $value);
}

function monitor_curl_setopt_array($ch, array $options)
{
    if (array_key_exists(CURLOPT_HTTPHEADER, $options)) {
        Monitor::$curlHeaders[(int) $ch] = (array) $options[CURLOPT_HTTPHEADER];
    }
    return curl_setopt_array($ch, $options);
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
                'method' => 'GET',
                'url'    => Monitor::safeUrl($this->url),
                'status' => $this->status,
                'client' => 'stream',
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
