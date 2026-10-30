<?php
// Banco SQLite do protótipo + clientes dos sistemas externos (servicos/ em 127.0.0.1:8070).
// No site real, troque "new PDO" por "new MonitorPDO" e "curl_exec" por "monitor_curl_exec".

const SERVICOS_URL = 'http://127.0.0.1:8070';

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $file = __DIR__ . '/../data/loja.sqlite';
    $novo = !is_file($file);
    if ($novo && !is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    $pdo = new MonitorPDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    if ($novo) {
        $pdo->exec('CREATE TABLE produtos (id INTEGER PRIMARY KEY, nome TEXT, preco REAL, categoria TEXT)');
        $pdo->exec('CREATE TABLE pedidos (id INTEGER PRIMARY KEY AUTOINCREMENT, produto_id INTEGER, valor REAL, transacao TEXT, criado_em TEXT)');
        $ins = $pdo->prepare('INSERT INTO produtos (id, nome, preco, categoria) VALUES (?, ?, ?, ?)');
        foreach (array(
            array(1, 'Café especial 250g', 39.90, 'Mercearia'),
            array(2, 'Caneca de cerâmica', 49.00, 'Casa'),
            array(3, 'Moedor manual', 189.90, 'Utensílios'),
            array(4, 'Filtro de papel (100 un.)', 14.50, 'Mercearia'),
            array(5, 'Chaleira bico de ganso', 259.00, 'Utensílios'),
            array(6, 'Balança digital', 129.90, 'Utensílios'),
        ) as $row) {
            $ins->execute($row);
        }
    }
    return $pdo;
}

/** Chamada HTTP via cURL, medida pelo monitor. Devolve array('status' => int, 'json' => mixed). */
function servico($metodo, $caminho, $dados = null, $timeout = 2)
{
    $ch = curl_init(SERVICOS_URL . $caminho);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLINFO_HEADER_OUT    => true,
    ));
    if ($dados !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    }
    $body = monitor_curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status' => $status, 'json' => $body === false ? null : json_decode($body, true));
}
