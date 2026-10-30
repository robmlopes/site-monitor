<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';
page_start('Produtos');
?>
<div class="card">
  <table>
    <tr><th>Produto</th><th>Categoria</th><th>Preço</th></tr>
    <?php foreach (produtos() as $id => $p): ?>
      <tr>
        <td><a href="/produto.php?id=<?php echo $id; ?>"><?php echo e($p['nome']); ?></a></td>
        <td><?php echo e($p['categoria']); ?></td>
        <td><?php echo preco($p['preco']); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<p class="muted">Teste também um <a href="/produto.php?id=99">produto inexistente</a> (404).</p>
<?php page_end();
