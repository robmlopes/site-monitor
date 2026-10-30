<?php
/**
 * Entrada única do agente (PHP 7.0+). Carregada antes da aplicação via loader.php / auto_prepend_file.
 * Mede cada requisição e grava um trace OpenTelemetry (OTLP/JSON) por linha.
 */

if (defined('MONITOR_AGENT_START')) {
    return;
}
define('MONITOR_AGENT_START', microtime(true));
define('MONITOR_HOME', __DIR__);

require __DIR__ . '/src/Monitor.php';
require __DIR__ . '/src/instrument.php';

call_user_func(function () {
    $config = require __DIR__ . '/config.php';
    if (is_file(__DIR__ . '/config.local.php')) {
        $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
    }
    Monitor::$config = $config;
});

require __DIR__ . '/src/agent.php';
