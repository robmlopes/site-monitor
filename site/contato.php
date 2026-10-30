<?php
require __DIR__ . '/includes/layout.php';

$erros = array();
$enviado = false;
$nome = isset($_POST['nome']) ? trim($_POST['nome']) : '';
$mensagem = isset($_POST['mensagem']) ? trim($_POST['mensagem']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($nome === '') {
        $erros[] = 'Informe o nome.';
    }
    if (mb_strlen($mensagem) < 5) {
        $erros[] = 'A mensagem precisa ter ao menos 5 caracteres.';
    }
    if ($erros) {
        http_response_code(422);
    } else {
        $enviado = true; // protótipo: não envia nada de verdade
    }
}
page_start('Contato');
?>
<?php if ($enviado): ?>
  <div class="card">Obrigado, <?php echo e($nome); ?>! Mensagem recebida. <a href="/contato.php">Enviar outra</a></div>
<?php else: ?>
  <?php if ($erros): ?><div class="card" style="border-color:#f1b0b0"><?php echo implode('<br>', array_map('e', $erros)); ?></div><?php endif; ?>
  <form class="card" method="post" style="display:grid;gap:10px;max-width:420px">
    <input name="nome" placeholder="Nome" value="<?php echo e($nome); ?>">
    <textarea name="mensagem" rows="4" placeholder="Mensagem"><?php echo e($mensagem); ?></textarea>
    <button>Enviar (POST)</button>
    <span class="muted">Envie vazio para testar a resposta 422.</span>
  </form>
<?php endif; ?>
<?php page_end();
