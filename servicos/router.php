<?php
// "Outro sistema" simulado para o protótipo: APIs com latência variável e falhas ocasionais.
// php -S 127.0.0.1:8070 -t servicos router.php
// Não usa o agente: do ponto de vista do site, isto é um sistema externo.

header('Content-Type: application/json; charset=utf-8');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function demora($min, $max)
{
    usleep(mt_rand($min, $max) * 1000);
}

function chance($pct)
{
    return mt_rand(1, 100) <= $pct;
}

function falha($code, $msg)
{
    http_response_code($code);
    echo json_encode(array('erro' => $msg));
    exit;
}

switch ($path) {
    case '/frete':
        demora(80, 400);
        $cep = isset($_GET['cep']) ? preg_replace('/\D/', '', $_GET['cep']) : '';
        if (strlen($cep) !== 8) {
            falha(400, 'CEP inválido');
        }
        echo json_encode(array('cep' => $cep, 'valor' => round(mt_rand(1500, 4500) / 100, 2), 'prazo_dias' => mt_rand(2, 9)));
        break;

    case '/estoque':
        chance(5) ? demora(2500, 3500) : demora(20, 100);
        echo json_encode(array('id' => isset($_GET['id']) ? (int) $_GET['id'] : 0, 'disponivel' => mt_rand(0, 40)));
        break;

    case '/cotacao':
        demora(100, 600);
        echo json_encode(array('moeda' => 'USD', 'valor' => round(mt_rand(500, 560) / 100, 2)));
        break;

    case '/pagamento':
        demora(400, 1500);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            falha(405, 'use POST');
        }
        if (chance(15)) {
            falha(502, 'gateway de pagamento indisponível');
        }
        echo json_encode(array('aprovado' => !chance(10), 'transacao' => strtoupper(bin2hex(random_bytes(6)))));
        break;

    default:
        falha(404, 'rota desconhecida');
}
