<?php
require_once __DIR__ . '/db.php';

function produtos()
{
    $lista = array();
    foreach (db()->query('SELECT id, nome, preco, categoria FROM produtos ORDER BY id') as $row) {
        $lista[(int) $row['id']] = $row;
    }
    return $lista;
}

function produto($id)
{
    $st = db()->prepare('SELECT id, nome, preco, categoria FROM produtos WHERE id = ?');
    $st->execute(array($id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

/** Cotação do dólar via file_get_contents (medido automaticamente: auto_http do agente). */
function cotacao_dolar()
{
    $ctx = stream_context_create(array('http' => array('timeout' => 2)));
    $body = @file_get_contents(SERVICOS_URL . '/cotacao', false, $ctx);
    $json = $body === false ? null : json_decode($body, true);
    return $json ? $json['valor'] : null;
}

function preco($v)
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}
