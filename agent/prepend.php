<?php
/**
 * Entrada única do agente (PHP 7.0+). Carregada antes da aplicação via loader.php / auto_prepend_file.
 * Mede cada requisição e grava um trace OpenTelemetry (OTLP/JSON) por linha.
 *
 * Qualquer falha ao carregar o agente (arquivo faltando ou com erro, config.local.php inválido) só desliga a
 * medição desta requisição e vai para o log do PHP: o site nunca cai por causa do monitor.
 */

if (defined('MONITOR_AGENT_START')) {
    return;
}
define('MONITOR_AGENT_START', microtime(true));
define('MONITOR_HOME', __DIR__);

call_user_func(function () {
    try {
        // require de arquivo inexistente é erro fatal que try/catch não pega: confere todos antes de carregar
        // (cópia do agente pela metade durante uma atualização).
        $files = array('/src/Monitor.php', '/src/instrument.php', '/config.php', '/src/agent.php');
        foreach ($files as $f) {
            if (!is_file(__DIR__ . $f)) {
                throw new RuntimeException("arquivo do agente ausente: $f");
            }
        }
        require_once __DIR__ . '/src/Monitor.php';
        require_once __DIR__ . '/src/instrument.php';

        $config = require __DIR__ . '/config.php';
        if (!is_array($config)) {
            throw new RuntimeException('config.php não devolveu um array');
        }
        if (is_file(__DIR__ . '/config.local.php')) {
            $local = require __DIR__ . '/config.local.php';
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            } else {
                @error_log('[monitor] config.local.php não devolveu um array; usando só o config.php');
            }
        }
        Monitor::$config = $config;

        require __DIR__ . '/src/agent.php';
    } catch (Exception $e) {
        monitor_load_failed($e);
    } catch (Error $e) {
        monitor_load_failed($e);
    }
});

function monitor_load_failed($e)
{
    if (class_exists('Monitor', false)) {
        Monitor::$start = null; // instrumentação já carregada vira no-op
    }
    @error_log('[monitor] agente desligado nesta requisição: ' . get_class($e) . ': ' . $e->getMessage()
        . ' em ' . $e->getFile() . ':' . $e->getLine());
}
