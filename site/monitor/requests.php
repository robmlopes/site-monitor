<?php
/**
 * Entrega ao painel as requisições gravadas pelo agent.php, de forma incremental.
 * GET ?offset=N  ->  {"next": M, "reset": bool, "requests": [...]}
 * O painel guarda "next" e manda de volta na próxima chamada.
 */

require __DIR__ . '/bootstrap.php';

$max = 500;
$file = __DIR__ . '/data/requests.log';
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

if (!is_file($file)) {
    echo json_encode(array('next' => 0, 'reset' => $offset > 0, 'requests' => array()));
    exit;
}

clearstatcache(true, $file);
$size = filesize($file);
$reset = $offset > $size; // arquivo foi rotacionado
if ($reset) {
    $offset = 0;
}

$requests = array();
$fp = fopen($file, 'rb');
fseek($fp, $offset);
$next = $offset;
while (count($requests) < $max && ($line = fgets($fp)) !== false) {
    if (substr($line, -1) !== "\n") {
        break; // linha ainda sendo escrita
    }
    $next += strlen($line);
    $row = json_decode($line, true);
    if (is_array($row)) {
        $requests[] = $row;
    }
}
fclose($fp);

echo json_encode(array('next' => $next, 'reset' => $reset, 'requests' => $requests));
