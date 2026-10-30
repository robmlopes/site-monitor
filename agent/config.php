<?php
/**
 * Configuração padrão do agente. Para ajustar num servidor sem mexer aqui, crie config.local.php
 * ao lado deste arquivo (fora do git) devolvendo só as chaves que mudam, ex.:
 *   <?php return array('sample_rate' => 0.05, 'server_name' => 'WEB-01');
 */

$server = getenv('MONITOR_SERVER_NAME');
if (!$server) {
    $server = getenv('COMPUTERNAME') ?: gethostname();
}

return array(
    // Nome do servidor de origem (deve bater com o X-Servername do IIS).
    'server_name'   => $server,

    // Onde gravar os traces: <data_dir>/<site>/traces-AAAAMMDD-HH.jsonl
    'data_dir'      => dirname(__DIR__) . '/data',
    'retention_hours' => 48,

    // Endpoints do painel, respondidos pelo próprio agente antes da aplicação.
    'endpoint_prefix' => '/_monitor/',
    'token'         => getenv('MONITOR_TOKEN') ?: 'dev-token',

    // Volume: toda requisição grava o resumo; as chamadas filhas só quando a requisição é lenta,
    // deu erro, ou cai na amostra (0.0 a 1.0).
    'slow_ms'       => 1000,
    'sample_rate'   => 1.0,
    'max_spans'     => 100,

    // Requisições de arquivos estáticos que chegam ao PHP (ex.: 404 reescrito para index.php) não são gravadas.
    'ignore_static' => true,
    'static_extensions' => array('css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
        'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp3', 'mp4', 'webm', 'pdf', 'zip'),

    // Medição sem editar o site.
    'auto_http'     => true,   // file_get_contents()/fopen() em URLs
    'propagate'     => true,   // envia "traceparent" nas chamadas HTTP de saída (liga traces entre sistemas)
    'shims_ttl_hours' => 24,   // regenera os shims de funções depois disso (ou ao apagar o arquivo)

    // Raiz da aplicação (no ZF2, a pasta acima de public/).
    'app_root'      => isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
        ? dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '\\/')) : getcwd(),

    // Nome do site por host (opcional). Sem entrada, usa o próprio host.
    'sites'         => array(),

    // ZF2: serviços de cache a medir (além dos detectados) e checagem de sessão no health.
    'zf2_cache_services' => array('cache'),
    'health_session_check' => true,
);
