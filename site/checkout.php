<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

// Fluxo com vários sistemas: banco -> estoque -> pagamento -> banco.
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$p = $_SERVER['REQUEST_METHOD'] === 'POST' ? produto($id) : null;
if (!$p) {
    http_response_code(400);
    page_start('Checkout');
    echo '<div class="card">Escolha um produto em <a href="/produtos.php">Produtos</a> e clique em Comprar.</div>';
    page_end();
    return;
}

$estoque = servico('GET', '/estoque?id=' . $id);
$pagamento = servico('POST', '/pagamento', array('valor' => $p['preco'], 'produto' => $id), 3);

$ok = $pagamento['status'] === 200 && !empty($pagamento['json']['aprovado']);
if ($ok) {
    $st = db()->prepare('INSERT INTO pedidos (produto_id, valor, transacao, criado_em) VALUES (?, ?, ?, ?)');
    $st->execute(array($id, $p['preco'], $pagamento['json']['transacao'], date('c')));
} else {
    http_response_code($pagamento['status'] === 200 ? 402 : 502);
}

page_start('Checkout');
?>
<div class="card">
  <?php if ($ok): ?>
    <p>Pedido de <b><?php echo e($p['nome']); ?></b> aprovado. Transação <code><?php echo e($pagamento['json']['transacao']); ?></code>.</p>
  <?php elseif ($pagamento['status'] === 200): ?>
    <p><span class="tag warn">402</span> Pagamento recusado pela operadora.</p>
  <?php else: ?>
    <p><span class="tag err">502</span> Gateway de pagamento indisponível (HTTP <?php echo (int) $pagamento['status']; ?>). Tente de novo.</p>
  <?php endif; ?>
  <p><a href="/produto.php?id=<?php echo $id; ?>">Voltar ao produto</a></p>
</div>
<?php page_end();
