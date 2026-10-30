<?php
// Autenticação dos endpoints do painel: header X-Monitor-Token igual ao 'token' da configuração.

// Warnings em HTML (xdebug) quebrariam o JSON.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sent = isset($_SERVER['HTTP_X_MONITOR_TOKEN']) ? $_SERVER['HTTP_X_MONITOR_TOKEN'] : '';
if (!hash_equals((string) Monitor::config('token'), (string) $sent)) {
    http_response_code(401);
    echo json_encode(array('error' => 'unauthorized'));
    exit;
}
