<?php
/**
 * Config + autenticação compartilhadas pelos endpoints do painel (health.php, requests.php).
 * Proteção: header "X-Monitor-Token" precisa bater com MONITOR_TOKEN
 * (variável de ambiente) ou com o valor em monitor/config.php.
 */

$config = array(
    'token'    => 'dev-token',
    'db'       => null,       // ex.: array('dsn' => 'mysql:host=127.0.0.1;dbname=site', 'user' => 'root', 'pass' => '')
    'writable' => array(),    // pastas que o site precisa conseguir escrever (uploads, cache...)
);
if (is_file(__DIR__ . '/config.php')) {
    $config = array_merge($config, require __DIR__ . '/config.php');
}
$envToken = getenv('MONITOR_TOKEN');
if ($envToken !== false && $envToken !== '') {
    $config['token'] = $envToken;
}

// Warnings em HTML (xdebug) quebrariam o JSON.
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sent = isset($_SERVER['HTTP_X_MONITOR_TOKEN']) ? $_SERVER['HTTP_X_MONITOR_TOKEN'] : '';
if (!hash_equals((string) $config['token'], (string) $sent)) {
    http_response_code(401);
    echo json_encode(array('error' => 'unauthorized'));
    exit;
}
