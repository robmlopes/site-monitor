<?php
// Site de exemplo (substitua pelo seu). Cada página passa pelo agente (agent/).
require __DIR__ . '/includes/layout.php';
page_start('Site de exemplo');
?>
<p>Cada acesso a este site é registrado pelo agente e aparece no painel por rota.
   Use o menu acima ou os atalhos abaixo para gerar tráfego.</p>

<div class="grid">
  <div class="card"><b><a href="/produtos.php">Produtos</a></b><p class="muted">Lista e páginas de detalhe com <code>?id=</code>.</p></div>
  <div class="card"><b><a href="/busca.php?q=caf%C3%A9">Busca</a></b><p class="muted">Rota com query string.</p></div>
  <div class="card"><b><a href="/contato.php">Contato</a></b><p class="muted">Formulário POST (200 ou 422).</p></div>
  <div class="card"><b><a href="/api/produtos.php">API JSON</a></b><p class="muted">Endpoint de API.</p></div>
  <div class="card"><b><a href="/lenta.php">Página lenta</a></b> <span class="tag warn">1–3 s</span><p class="muted">Para ver a latência subir.</p></div>
  <div class="card"><b><a href="/erro.php">Erro 500</a></b> <span class="tag err">exceção</span><p class="muted">Exceção não tratada.</p></div>
  <div class="card"><b><a href="/nao-existe.php">Página 404</a></b> <span class="tag warn">404</span><p class="muted">Rota inexistente.</p></div>
  <div class="card"><b><a href="/testes.php">Testes</a></b><p class="muted">Gera tráfego automático em todas as rotas.</p></div>
</div>
<?php page_end();
