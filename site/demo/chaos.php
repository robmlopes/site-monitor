<?php
// Só para o protótipo: simula problemas no site para ver o painel reagir.
// Uso: /demo/chaos.php?mode=ok|slow|error|down
$file = __DIR__ . '/chaos.txt';
$modes = array('ok', 'slow', 'error', 'down');

if (isset($_GET['mode']) && in_array($_GET['mode'], $modes, true)) {
    file_put_contents($file, $_GET['mode']);
}
$current = is_file($file) ? trim(file_get_contents($file)) : 'ok';

header('Content-Type: text/plain; charset=utf-8');
echo "modo atual: $current\n";
