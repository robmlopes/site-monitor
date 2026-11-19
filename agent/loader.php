<?php
/**
 * Loader do monitor — este arquivo vai DENTRO de cada site (pode ir para o git do site).
 * O .user.ini do site aponta para ele:
 *   auto_prepend_file = "D:\Sites\<site>\config\monitor-loader.php"
 *
 * Só carrega o agente se ele estiver instalado neste servidor. Sem o monitor, não faz nada:
 * o site nunca cai por causa dele (um auto_prepend_file inexistente derrubaria todas as páginas).
 *
 * Onde procurar o agente, em ordem: variável de ambiente MONITOR_HOME, diretiva monitor.home
 * do php.ini, a própria pasta deste arquivo (quando o .user.ini aponta direto para agent/loader.php)
 * e as pastas padrão D:\monitor\agent e C:\monitor\agent.
 */
call_user_func(function () {
    foreach (array(getenv('MONITOR_HOME'), get_cfg_var('monitor.home'), __DIR__,
        'D:/monitor/agent', 'C:/monitor/agent') as $home) {
        if ($home && is_file($file = rtrim($home, '\\/') . '/prepend.php')) {
            // Um prepend.php com erro (atualização pela metade) não pode derrubar o site. include, não require: um
            // arquivo sem permissão de leitura (IIS lendo como IUSR) faria o require dar erro fatal.
            try {
                if (!is_readable($file) || (@include $file) === false) {
                    @error_log('[monitor] agente sem permissão de leitura, desligado: ' . $file);
                }
            } catch (Exception $e) {
                @error_log('[monitor] falha ao carregar o agente: ' . $e->getMessage());
            } catch (Error $e) {
                @error_log('[monitor] falha ao carregar o agente: ' . $e->getMessage());
            }
            return;
        }
    }
});

/*
 * Substitutos neutros. Sites que trocaram uma linha para medir (new MonitorPDO, monitor_curl_exec, Monitor::span...)
 * dependem dessas classes e funções. Se o agente não estiver instalado ou falhar ao carregar, elas passam a ser
 * as nativas, sem medição: o site continua funcionando exatamente como antes da troca.
 */
if (!class_exists('Monitor', false)) {
    final class Monitor
    {
        public static $start;
        public static $route;
        public static $error;
        public static $config = array();
        public static function config($key, $default = null) { return $default; }
        public static function span($type, $target, $op, $fn) { return $fn(); }
        public static function wrap($client, $type = null, $target = null) { return $client; }
        public static function record() {}
        public static function guard($fn, $default = null) { return $default; }
    }
}
if (!class_exists('MonitorPDO', false) && class_exists('PDO', false)) {
    class MonitorPDO extends PDO {}
}
if (!class_exists('MonitorMysqli', false) && class_exists('mysqli', false)) {
    class MonitorMysqli extends mysqli {}
}
if (!class_exists('MonitorSoapClient', false) && class_exists('SoapClient', false)) {
    class MonitorSoapClient extends SoapClient {}
}
if (!function_exists('monitor_curl_exec')) {
    function monitor_curl_exec($ch) { return curl_exec($ch); }
}
if (!function_exists('monitor_curl_setopt')) {
    function monitor_curl_setopt($ch, $option, $value) { return curl_setopt($ch, $option, $value); }
}
if (!function_exists('monitor_curl_setopt_array')) {
    function monitor_curl_setopt_array($ch, array $options) { return curl_setopt_array($ch, $options); }
}
