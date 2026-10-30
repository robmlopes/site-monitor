<?php
require __DIR__ . '/includes/layout.php';

$ms = mt_rand(1000, 3000);
usleep($ms * 1000); // simula uma consulta pesada
page_start('Página lenta');
?>
<div class="card">Esta página levou <b><?php echo $ms; ?> ms</b> de propósito. Veja a latência da rota <code>/lenta.php</code> no painel.</div>
<p><a href="/lenta.php">Carregar de novo</a></p>
<?php page_end();
