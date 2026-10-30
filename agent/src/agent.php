<?php
/**
 * Ciclo de vida da requisição: decide se mede, inicia o trace e, no fim, exporta em OTLP/JSON
 * (uma linha por trace) em <data_dir>/<site>/traces-AAAAMMDD-HH.jsonl.
 */

call_user_func(function () {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = $path ? preg_replace('#/index\.php$#', '/', $path) : '/';
    $host = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'desconhecido'));
    $sites = Monitor::config('sites', array());
    Monitor::$site = isset($sites[$host]) ? $sites[$host] : $host;

    // Endpoints do painel: respondidos aqui, antes da aplicação, sem arquivos na pasta pública do site.
    $prefix = Monitor::config('endpoint_prefix', '/_monitor/');
    if (strpos($path, $prefix) === 0) {
        $endpoint = basename($path, '.php');
        if (in_array($endpoint, array('health', 'traces'), true)) {
            require MONITOR_HOME . '/src/endpoints/' . $endpoint . '.php';
            exit;
        }
    }

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if (strpos($ua, 'MonitorPanel') !== false) {
        return; // checagens de uptime do próprio painel
    }
    if (Monitor::config('ignore_static', true)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, Monitor::config('static_extensions', array()), true)) {
            return;
        }
    }

    Monitor::$start = MONITOR_AGENT_START;
    Monitor::startTrace(isset($_SERVER['HTTP_TRACEPARENT']) ? $_SERVER['HTTP_TRACEPARENT'] : null);
    if (Monitor::config('auto_http')) {
        MonitorHttpStream::enable();
    }

    // Integração Zend Framework 2 (rota, erros, Doctrine, cache, Zend\Http\Client) + shims de funções.
    $root = Monitor::config('app_root');
    if (is_file($root . '/config/application.config.php') && is_dir($root . '/module')) {
        require MONITOR_HOME . '/zf2/autoload.php';
        \Monitor\Zf2\FunctionShims::load(
            array($root . '/module'),
            Monitor::siteDir() . '/generated/shims.php',
            array('Zend\Session' => array('session_start'))
        );
    }

    // CPU do processo: no IIS FastCGI / PHP-FPM cada processo atende uma requisição por vez,
    // então a diferença é da requisição. No Windows a precisão é de ~15,6 ms.
    $cpuStart = getrusage();

    register_shutdown_function(function () use ($path, $ua, $cpuStart) {
        Monitor::finalize();
        $end = microtime(true);
        $status = http_response_code();
        $status = $status ? $status : 200;
        $error = Monitor::$error;
        $last = error_get_last();
        if ($last && in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            $status = 500;
            $error = strtok($last['message'], "\n");
        }

        $cpu = getrusage();
        $usage = function ($r, $k) {
            return $r["ru_$k.tv_sec"] * 1000 + $r["ru_$k.tv_usec"] / 1000;
        };
        $cpuUser = max(0, $usage($cpu, 'utime') - $usage($cpuStart, 'utime'));
        $cpuSys = max(0, $usage($cpu, 'stime') - $usage($cpuStart, 'stime'));

        $extMs = Monitor::$droppedMs;
        foreach (Monitor::$spans as $s) {
            $extMs += ($s['end'] - $s['start']) * 1000;
        }
        $totalMs = ($end - MONITOR_AGENT_START) * 1000;
        $calls = count(Monitor::$spans) + Monitor::$dropped;

        // Amostragem: o resumo sempre; as chamadas filhas só se lenta, com erro ou sorteada.
        $detailed = $status >= 500 || $error !== null || $totalMs >= Monitor::config('slow_ms', 1000)
            || mt_rand() / mt_getrandmax() < Monitor::config('sample_rate', 1.0);

        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $route = Monitor::$route ? Monitor::$route : $path;
        $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) : null;

        $root = array(
            'traceId' => Monitor::$traceId,
            'spanId'  => Monitor::$spanId,
            'name'    => "$method $route",
            'kind'    => 2, // SERVER
            'startTimeUnixNano' => monitor_nanos(MONITOR_AGENT_START),
            'endTimeUnixNano'   => monitor_nanos($end),
            'attributes' => monitor_attrs(array(
                'http.request.method'       => $method,
                'http.route'                => Monitor::utf8($route),
                'url.path'                  => Monitor::utf8($path),
                'url.query'                 => Monitor::utf8(substr($query, 0, 300)),
                'http.response.status_code' => (int) $status,
                'client.address'            => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                'user_agent.original'       => Monitor::utf8(substr($ua, 0, 200)),
                'http.request.header.referer' => $referer ? Monitor::utf8($referer) : null,
                'monitor.cpu_ms'            => round($cpuUser + $cpuSys, 2),
                'monitor.cpu_user_ms'       => round($cpuUser, 2),
                'monitor.cpu_sys_ms'        => round($cpuSys, 2),
                'monitor.memory.peak'       => memory_get_peak_usage(false),
                'monitor.memory.reserved'   => memory_get_peak_usage(true),
                'monitor.ext_ms'            => round($extMs, 2),
                'monitor.ext_calls'         => $calls,
                'monitor.detailed'          => $detailed,
                'monitor.spans_dropped'     => Monitor::$dropped,
            )),
            'status' => $error !== null || $status >= 500
                ? array('code' => 2, 'message' => mb_substr(Monitor::utf8($error !== null ? $error : "HTTP $status"), 0, 300, 'UTF-8'))
                : array('code' => 0),
        );
        if (Monitor::$parentSpanId) {
            $root['parentSpanId'] = Monitor::$parentSpanId;
        }

        $spans = array($root);
        if ($detailed) {
            foreach (Monitor::$spans as $s) {
                $spans[] = monitor_otel_span($s);
            }
        }

        $line = json_encode(array('resourceSpans' => array(array(
            'resource' => array('attributes' => monitor_attrs(array(
                'service.name'           => Monitor::$site,
                'host.name'              => Monitor::config('server_name'),
                'process.runtime.name'   => 'php',
                'process.runtime.version' => PHP_VERSION,
                'telemetry.sdk.name'     => 'php-monitor',
                'telemetry.sdk.language' => 'php',
                'telemetry.sdk.version'  => Monitor::VERSION,
            ))),
            'scopeSpans' => array(array(
                'scope' => array('name' => 'php-monitor', 'version' => Monitor::VERSION),
                'spans' => $spans,
            )),
        ))), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $dir = Monitor::siteDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/traces-' . date('Ymd-H') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
    });
});

function monitor_nanos($t)
{
    return sprintf('%.0f', $t * 1e9);
}

/** Atributos OTLP/JSON: [{key, value: {stringValue|intValue|doubleValue|boolValue}}]. Nulos são omitidos. */
function monitor_attrs(array $map)
{
    $out = array();
    foreach ($map as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (is_bool($v)) {
            $value = array('boolValue' => $v);
        } elseif (is_int($v)) {
            $value = array('intValue' => (string) $v);
        } elseif (is_float($v)) {
            $value = array('doubleValue' => $v);
        } else {
            $value = array('stringValue' => (string) $v);
        }
        $out[] = array('key' => $k, 'value' => $value);
    }
    return $out;
}

/** Converte um span interno em span OTLP (CLIENT), com atributos semânticos + monitor.type/target/op. */
function monitor_otel_span(array $s)
{
    $d = isset($s['detail']) ? $s['detail'] : array();
    $attrs = array('monitor.type' => $s['type'], 'monitor.target' => $s['target'], 'monitor.op' => $s['op']);
    $host = $s['target'];

    if ($s['type'] === 'db') {
        $parts = explode(' ', $s['target'], 2);
        $systems = array('sqlsrv' => 'mssql', 'pgsql' => 'postgresql', 'mysql' => 'mysql', 'sqlite' => 'sqlite');
        $attrs['db.system'] = isset($systems[$parts[0]]) ? $systems[$parts[0]] : $parts[0];
        $attrs['db.statement'] = $s['op'];
        $hostDb = isset($parts[1]) ? explode('/', $parts[1], 2) : array('');
        $host = $hostDb[0];
        $attrs['db.name'] = isset($hostDb[1]) ? $hostDb[1] : null;
        $name = strtoupper(strtok($s['op'], ' ')) . ($attrs['db.name'] ? ' ' . $attrs['db.name'] : '');
    } elseif ($s['type'] === 'http') {
        $attrs['http.request.method'] = isset($d['method']) ? $d['method'] : null;
        $attrs['url.full'] = isset($d['url']) ? $d['url'] : null;
        $attrs['http.response.status_code'] = isset($d['status']) ? (int) $d['status'] : null;
        foreach (array('dns', 'connect', 'tls', 'ttfb', 'total') as $k) {
            if (isset($d[$k])) {
                $attrs["monitor.http.{$k}_ms"] = (float) $d[$k];
            }
        }
        $attrs['monitor.http.bytes'] = isset($d['bytes']) ? (int) $d['bytes'] : null;
        $attrs['monitor.http.client'] = isset($d['client']) ? $d['client'] : null;
        $name = (isset($d['method']) ? $d['method'] : 'HTTP') . ' ' . $s['target'];
    } elseif ($s['type'] === 'redis') {
        $attrs['db.system'] = 'redis';
        $attrs['db.operation'] = $s['op'];
        $host = preg_replace('/\s.*$/', '', $s['target']);
        $name = 'redis ' . $s['op'];
    } else {
        $name = $s['type'] . ' ' . $s['op'];
    }
    $hp = explode(':', $host);
    $attrs['server.address'] = $hp[0] !== '' ? $hp[0] : null;
    $attrs['server.port'] = isset($hp[1]) && ctype_digit($hp[1]) ? (int) $hp[1] : null;

    $span = array(
        'traceId' => Monitor::$traceId,
        'spanId'  => $s['id'],
        'parentSpanId' => Monitor::$spanId,
        'name'    => mb_substr($name, 0, 120, 'UTF-8'),
        'kind'    => 3, // CLIENT
        'startTimeUnixNano' => monitor_nanos($s['start']),
        'endTimeUnixNano'   => monitor_nanos($s['end']),
        'attributes' => monitor_attrs($attrs),
        'status' => $s['ok'] ? array('code' => 0) : array('code' => 2, 'message' => isset($s['error']) ? $s['error'] : 'erro'),
    );
    return $span;
}
