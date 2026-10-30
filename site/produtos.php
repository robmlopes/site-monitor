<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

$lista = produtos();
$dolar = cotacao_dolar();
page_start('Produtos');
?>
<div class="card">
  <table>
    <tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Em US$</th></tr>
    <?php foreach ($lista as $id => $p): ?>
      <tr>
        <td><a href="/produto.php?id=<?php echo $id; ?>"><?php echo e($p['nome']); ?></a></td>
        <td><?php echo e($p['categoria']); ?></td>
        <td><?php echo preco($p['preco']); ?></td>
        <td class="muted"><?php echo $dolar ? 'US$ ' . number_format($p['preco'] / $dolar, 2) : '–'; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<p class="muted">
  Lista vinda do SQLite; cotação do dólar <?php echo $dolar ? 'R$ ' . number_format($dolar, 2, ',', '.') : 'indisponível'; ?>
  via <code>file_get_contents</code> no serviço externo.
  Teste também um <a href="/produto.php?id=99">produto inexistente</a> (404).
</p>
<?php page_end();
