<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$achados = array();
if ($q !== '') {
    foreach (produtos() as $id => $p) {
        if (mb_stripos($p['nome'] . ' ' . $p['categoria'], $q) !== false) {
            $achados[$id] = $p;
        }
    }
}
page_start('Busca');
?>
<form class="card" method="get">
  <input name="q" value="<?php echo e($q); ?>" placeholder="ex.: café, moedor" autofocus>
  <button>Buscar</button>
  <span class="muted">Sugestões:
    <a href="?q=caf%C3%A9">café</a>, <a href="?q=utens%C3%ADlios">utensílios</a>, <a href="?q=xyz">sem resultado</a></span>
</form>
<?php if ($q !== ''): ?>
  <div class="card">
    <p><?php echo count($achados); ?> resultado(s) para <b><?php echo e($q); ?></b></p>
    <ul>
      <?php foreach ($achados as $id => $p): ?>
        <li><a href="/produto.php?id=<?php echo $id; ?>"><?php echo e($p['nome']); ?></a> — <?php echo preco($p['preco']); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php page_end();
