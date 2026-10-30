<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$p = produto($id);
if (!$p) {
    require __DIR__ . '/includes/404.php';
    return;
}

$estoque = servico('GET', '/estoque?id=' . $id);
$cep = isset($_GET['cep']) ? $_GET['cep'] : '';
$frete = $cep !== '' ? servico('GET', '/frete?cep=' . urlencode($cep)) : null;

page_start($p['nome']);
?>
<div class="card">
  <p><span class="tag"><?php echo e($p['categoria']); ?></span></p>
  <p style="font-size:22px"><b><?php echo preco($p['preco']); ?></b></p>
  <p class="muted">Produto #<?php echo $id; ?> ·
    <?php if ($estoque['status'] === 200): ?>
      <?php echo (int) $estoque['json']['disponivel']; ?> em estoque
    <?php else: ?>
      <span class="tag err">estoque indisponível</span>
    <?php endif; ?>
  </p>

  <form method="get" style="margin:12px 0">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <input name="cep" placeholder="CEP para frete" value="<?php echo e($cep); ?>">
    <button>Calcular frete</button>
    <?php if ($frete && $frete['status'] === 200): ?>
      <span><?php echo preco($frete['json']['valor']); ?> · <?php echo (int) $frete['json']['prazo_dias']; ?> dias</span>
    <?php elseif ($frete): ?>
      <span class="tag warn">frete: <?php echo e($frete['json'] ? $frete['json']['erro'] : 'sem resposta'); ?></span>
    <?php endif; ?>
  </form>

  <form method="post" action="/checkout.php">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <button>Comprar (chama o pagamento)</button>
  </form>
</div>
<p>
  <?php if ($id > 1): ?><a href="/produto.php?id=<?php echo $id - 1; ?>">← anterior</a> · <?php endif; ?>
  <a href="/produtos.php">todos os produtos</a>
  <?php if ($id < 6): ?> · <a href="/produto.php?id=<?php echo $id + 1; ?>">próximo →</a><?php endif; ?>
</p>
<?php page_end();
