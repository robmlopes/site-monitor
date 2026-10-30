<?php
/**
 * Agente de rastreamento por rota: grava cada requisição em monitor/data/requests.log
 * (uma linha JSON por acesso), que o painel lê via monitor/requests.php.
 *
 * No site real (Apache), carregue antes de qualquer script:
 *   .htaccess:  php_value auto_prepend_file "/caminho/do/site/monitor/agent.php"
 *   ou php.ini: auto_prepend_file = "/caminho/do/site/monitor/agent.php"
 * Em dev, o router.php do php -S já faz o require.
 */

if (defined('MONITOR_AGENT_START')) {
    return;
}
define('MONITOR_AGENT_START', microtime(true));

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

    register_shutdown_function(function () use ($path, $ua) {
        $status = http_response_code();
        $error = null;
        $last = error_get_last();
        if ($last && in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            $status = 500;
            $error = substr(strtok($last['message'], "\n"), 0, 200);
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
            'mem'      => memory_get_peak_usage(true),
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
        @file_put_contents($file, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
    });
});
