<?php
/**
 * Arquivo igual para todos os sites ZF2: copie para <site>\config\autoload\monitor.local.php
 * (o .gitignore padrão do ZF2 ignora *local.php; pode também ir para o git do site).
 *
 * Só tem efeito com o agente carregado. Sem ele, as classes Monitor\Zf2 não existem e
 * este arquivo devolve uma configuração vazia: o site segue normal.
 *
 * O resto (conexões do Doctrine, serviços de cache, Zend\Http\Client) é descoberto em runtime
 * pelo ApplicationDelegator.
 */

if (!class_exists('Monitor\Zf2\ApplicationDelegator')) {
    return array();
}

return array(
    'service_manager' => array(
        'delegators' => array(
            'Application' => array('Monitor\Zf2\ApplicationDelegator'),
        ),
    ),
);
