<?php
require __DIR__ . '/includes/layout.php';

// Rotas usadas pelo gerador de tráfego. peso = chance relativa de ser sorteada.
$rotas = array(
    array('GET', '/', 6),
    array('GET', '/produtos.php', 5),
    array('GET', '/produto.php?id=1', 3),
    array('GET', '/produto.php?id=3', 3),
    array('GET', '/produto.php?id=5', 2),
    array('GET', '/produto.php?id=99', 1),
    array('GET', '/busca.php?q=caf%C3%A9', 3),
    array('GET', '/busca.php?q=moedor', 2),
    array('GET', '/api/produtos.php', 4),
    array('POST', '/contato.php', 2),
    array('POST', '/contato.php?invalido', 1),
    array('GET', '/lenta.php', 1),
    array('GET', '/erro.php', 1),
    array('GET', '/nao-existe.php', 1),
);
page_start('Testes');
?>
<div class="card">
  <p>Dispara requisições sorteadas para as rotas abaixo, uma de cada vez, e acompanhe no
     <a href="<?php echo PANEL_URL; ?>" target="_blank">painel</a>.</p>
  <button onclick="gerar(10)">Gerar 10 acessos</button>
  <button onclick="gerar(50)">Gerar 50 acessos</button>
  <button onclick="parar = true" style="background:#fff;color:#1d2330;border-color:#cdd3dc">Parar</button>
  <span id="progresso" class="muted"></span>
</div>

<div class="grid">
  <div class="card">
    <b>Rotas</b>
    <table>
      <?php foreach ($rotas as $r): ?>
        <tr><td><span class="tag"><?php echo $r[0]; ?></span></td>
            <td><?php if ($r[0] === 'GET'): ?><a href="<?php echo e($r[1]); ?>"><?php echo e($r[1]); ?></a><?php else: echo e($r[1]); endif; ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="card">
    <b>Log</b>
    <table id="log"></table>
  </div>
</div>

<script>
const rotas = <?php echo json_encode($rotas); ?>;
const total = rotas.reduce((s, r) => s + r[2], 0);
let parar = false;

function sortear() {
  let n = Math.random() * total;
  for (const r of rotas) { if ((n -= r[2]) < 0) return r; }
  return rotas[0];
}

async function gerar(qtd) {
  parar = false;
  const log = document.getElementById('log');
  for (let i = 1; i <= qtd && !parar; i++) {
    const [metodo, url] = sortear();
    const opts = { method: metodo };
    if (metodo === 'POST') {
      opts.body = new URLSearchParams(url.includes('invalido') ? { nome: '' } : { nome: 'Teste', mensagem: 'Mensagem automática' });
    }
    const t = performance.now();
    let status;
    try { status = (await fetch(url, opts)).status; } catch (e) { status = 'falha'; }
    const ms = Math.round(performance.now() - t);
    const cls = status >= 500 || status === 'falha' ? 'err' : status >= 400 ? 'warn' : '';
    log.insertAdjacentHTML('afterbegin', `<tr><td>${metodo}</td><td>${url}</td><td><span class="tag ${cls}">${status}</span></td><td class="muted">${ms} ms</td></tr>`);
    while (log.rows.length > 30) log.deleteRow(-1);
    document.getElementById('progresso').textContent = `${i}/${qtd}`;
  }
}
</script>
<?php page_end();
