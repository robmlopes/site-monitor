<?php
require __DIR__ . '/../includes/chaos.php';
require __DIR__ . '/../includes/data.php';

header('Content-Type: application/json; charset=utf-8');
$lista = array();
foreach (produtos() as $id => $p) {
    $lista[] = array('id' => $id) + $p;
}
echo json_encode(array('total' => count($lista), 'produtos' => $lista), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
