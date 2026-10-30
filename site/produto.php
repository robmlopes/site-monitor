<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$lista = produtos();
if (!isset($lista[$id])) {
    require __DIR__ . '/includes/404.php';
    return;
}
$p = $lista[$id];
page_start($p['nome']);
?>
<div class="card">
  <p><span class="tag"><?php echo e($p['categoria']); ?></span></p>
  <p style="font-size:22px"><b><?php echo preco($p['preco']); ?></b></p>
  <p class="muted">Produto #<?php echo $id; ?></p>
</div>
<p>
  <?php if (isset($lista[$id - 1])): ?><a href="/produto.php?id=<?php echo $id - 1; ?>">← anterior</a> · <?php endif; ?>
  <a href="/produtos.php">todos os produtos</a>
  <?php if (isset($lista[$id + 1])): ?> · <a href="/produto.php?id=<?php echo $id + 1; ?>">próximo →</a><?php endif; ?>
</p>
<?php page_end();
