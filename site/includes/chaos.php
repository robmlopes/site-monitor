<?php
// Aplica o modo de /demo/chaos.php a todas as páginas que usam o layout.
function chaos_mode()
{
    $file = __DIR__ . '/../demo/chaos.txt';
    return is_file($file) ? trim(file_get_contents($file)) : 'ok';
}

$mode = chaos_mode();
if ($mode === 'slow') {
    usleep(mt_rand(1200, 2500) * 1000);
} elseif ($mode === 'error') {
    http_response_code(500);
    echo 'Erro interno simulado';
    exit;
} elseif ($mode === 'down') {
    sleep(8); // estoura o timeout do painel (5s); o php -S é single-thread, então não exagere
}
