<?php
/**
 * Agente de rastreamento por rota: grava cada requisição em monitor/data/requests.log
 * (uma linha JSON por acesso), que o painel lê via monitor/requests.php.
 * Mede tempo, CPU (usuário + sistema), memória e as chamadas a outros sistemas (instrument.php).
 *
 * No site real (Apache), carregue antes de qualquer script:
 *   .htaccess:  php_value auto_prepend_file "/caminho/do/site/monitor/agent.php"
 *   ou php.ini: auto_prepend_file = "/caminho/do/site/monitor/agent.php"
 * Em dev, o router.php do php -S já faz o require.
 *
 * Para medir file_get_contents()/fopen() em URLs sem mudar o site, defina antes do agente:
 *   define('MONITOR_AUTO_HTTP', true);   (ou SetEnv MONITOR_AUTO_HTTP 1 no Apache)
 */

if (defined('MONITOR_AGENT_START')) {
    return;
}
define('MONITOR_AGENT_START', microtime(true));
require_once __DIR__ . '/instrument.php';

call_user_func(function () {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = $path ? preg_replace('#/index\.php$#', '/', $path) : '/';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

    // Não registra os próprios endpoints do monitor nem as checagens de uptime do painel.
    if (strpos($path, '/monitor/') === 0 || strpos($ua, 'MonitorPanel') !== false) {
        return;
    }

    Monitor::$start = MONITOR_AGENT_START;
    if ((defined('MONITOR_AUTO_HTTP') && MONITOR_AUTO_HTTP) || getenv('MONITOR_AUTO_HTTP') === '1') {
        MonitorHttpStream::enable();
    }

    // CPU do processo: no Apache prefork/FPM o processo atende uma requisição por vez, então a diferença
    // é da requisição. Em servidores multi-thread (Apache no Windows) inclui outras threads.
    $cpuStart = getrusage();

    register_shutdown_function(function () use ($path, $ua, $cpuStart) {
        $status = http_response_code();
        $error = null;
        $last = error_get_last();
        if ($last && in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            $status = 500;
            $error = substr(strtok($last['message'], "\n"), 0, 200);
        }

        $cpu = getrusage();
        $usage = function ($r, $k) {
            return $r["ru_$k.tv_sec"] * 1000 + $r["ru_$k.tv_usec"] / 1000;
        };
        $cpuUser = max(0, $usage($cpu, 'utime') - $usage($cpuStart, 'utime'));
        $cpuSys = max(0, $usage($cpu, 'stime') - $usage($cpuStart, 'stime'));

        $extMs = 0;
        foreach (Monitor::$spans as $s) {
            $extMs += $s['ms'];
        }

        $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $record = array(
            'id'       => uniqid('', true),
            'ts'       => date('Y-m-d\TH:i:s', (int) MONITOR_AGENT_START) . sprintf('.%03d', (MONITOR_AGENT_START - floor(MONITOR_AGENT_START)) * 1000) . date('P'),
            'method'   => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            'route'    => $path,
            'query'    => substr($query, 0, 200),
            'status'   => $status ? $status : 200,
            'ms'       => round((microtime(true) - MONITOR_AGENT_START) * 1000, 2),
            'cpuMs'    => round($cpuUser + $cpuSys, 2),
            'cpuUserMs' => round($cpuUser, 2),
            'cpuSysMs' => round($cpuSys, 2),
            'mem'      => memory_get_peak_usage(true),   // reservado pelo PHP (blocos de 2 MB)
            'memPeak'  => memory_get_peak_usage(false),  // efetivamente usado pelo script
            'extMs'    => round($extMs, 2),
            'spans'    => Monitor::$spans,
            'spansDropped' => Monitor::$dropped,
            'ip'       => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
            'ua'       => substr($ua, 0, 120),
            'referer'  => isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) : null,
            'error'    => $error,
        );

        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/requests.log';
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1'); // o painel percebe o arquivo menor e recomeça do início
        }
        @file_put_contents($file, json_encode($record, JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    });
});
