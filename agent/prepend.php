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

/**
 * Carrega um arquivo do agente sem risco de erro fatal. Todo arquivo do agente entra por aqui, nunca por require.
 *
 * require de um arquivo ausente ou SEM PERMISSÃO DE LEITURA é erro fatal que try/catch não pega. Isso acontece, por
 * exemplo, com o IIS lendo como IUSR (fastcgi.impersonate=1) um arquivo liberado só para IIS_IUSRS. O include, no mesmo
 * caso, só devolve false. Aqui isso vira exceção, que desliga só a medição.
 */
function monitor_include($file, $once = false)
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('arquivo do agente ausente ou sem permissão de leitura: ' . $file);
    }
    $result = $once ? @include_once $file : @include $file;
    if ($result === false) {
        throw new RuntimeException('não foi possível ler o arquivo do agente (permissão?): ' . $file);
    }
    return $result;
}

call_user_func(function () {
    try {
        // Confere todos antes de carregar o primeiro: uma cópia do agente pela metade (atualização em andamento) não
        // pode deixar metade das classes definidas.
        $files = array('/src/Monitor.php', '/src/instrument.php', '/config.php', '/src/agent.php');
        foreach ($files as $f) {
            if (!is_file(__DIR__ . $f) || !is_readable(__DIR__ . $f)) {
                throw new RuntimeException("arquivo do agente ausente ou sem permissão de leitura: $f");
            }
        }
        monitor_include(__DIR__ . '/src/Monitor.php', true);
        monitor_include(__DIR__ . '/src/instrument.php', true);

        $config = monitor_include(__DIR__ . '/config.php');
        if (!is_array($config)) {
            throw new RuntimeException('config.php não devolveu um array');
        }
        if (is_file(__DIR__ . '/config.local.php')) {
            $local = monitor_include(__DIR__ . '/config.local.php');
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            } else {
                @error_log('[monitor] config.local.php não devolveu um array; usando só o config.php');
            }
        }
        Monitor::$config = $config;

        monitor_include(__DIR__ . '/src/agent.php');
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
