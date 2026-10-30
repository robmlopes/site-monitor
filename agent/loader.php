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
 * e C:\monitor\agent.
 */
call_user_func(function () {
    foreach (array(getenv('MONITOR_HOME'), get_cfg_var('monitor.home'), __DIR__, 'C:/monitor/agent') as $home) {
        if ($home && is_file($file = rtrim($home, '\\/') . '/prepend.php')) {
            require $file;
            return;
        }
    }
});
