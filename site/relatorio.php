<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

// Página pesada de propósito: muita CPU (cálculos e ordenação) e memória (array grande em RAM).
$n = isset($_GET['n']) ? max(1000, min(400000, (int) $_GET['n'])) : 150000;
$produtos = produtos();
$pedidos = (int) db()->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();

mt_srand(42);
$vendas = array();
for ($i = 0; $i < $n; $i++) {
    $pid = mt_rand(1, 6);
    $vendas[] = array('produto' => $pid, 'qtd' => mt_rand(1, 5), 'valor' => $produtos[$pid]['preco'], 'hash' => md5($i));
}
usort($vendas, function ($a, $b) {
    return $b['valor'] * $b['qtd'] - $a['valor'] * $a['qtd'];
});
$porProduto = array();
foreach ($vendas as $v) {
    $k = $v['produto'];
    if (!isset($porProduto[$k])) {
        $porProduto[$k] = array('qtd' => 0, 'total' => 0);
    }
    $porProduto[$k]['qtd'] += $v['qtd'];
    $porProduto[$k]['total'] += $v['qtd'] * $v['valor'];
}
arsort($porProduto);

page_start('Relatório de vendas');
?>
<div class="card">
  <p><?php echo number_format($n, 0, ',', '.'); ?> vendas simuladas processadas em memória ·
     <?php echo $pedidos; ?> pedidos reais no banco ·
     pico de memória <?php echo round(memory_get_peak_usage() / 1048576, 1); ?> MB</p>
  <table>
    <tr><th>Produto</th><th>Itens</th><th>Total</th></tr>
    <?php foreach ($porProduto as $pid => $t): ?>
      <tr><td><?php echo e($produtos[$pid]['nome']); ?></td><td><?php echo number_format($t['qtd'], 0, ',', '.'); ?></td><td><?php echo preco($t['total']); ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="muted">Tamanho: <a href="?n=50000">pequeno</a> · <a href="?n=150000">médio</a> · <a href="?n=400000">grande</a></p>
</div>
<?php page_end();
