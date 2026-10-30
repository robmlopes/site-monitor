<?php
require __DIR__ . '/includes/layout.php';

// Exceção não tratada de propósito: o agente registra a rota como 500 com a mensagem.
ini_set('display_errors', '0');
http_response_code(500);
throw new RuntimeException('Falha simulada ao processar o pedido');
