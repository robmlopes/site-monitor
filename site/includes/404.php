<?php
require_once __DIR__ . '/layout.php';
http_response_code(404);
page_start('Página não encontrada');
?>
<div class="card">
  <p>A rota <code><?php echo e(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); ?></code> não existe.</p>
  <p><a href="/">Voltar para o início</a></p>
</div>
<?php page_end();
