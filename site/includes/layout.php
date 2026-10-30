<?php
require_once __DIR__ . '/chaos.php';

const PANEL_URL = 'http://localhost:5050';

function nav_links()
{
    return array(
        '/'                 => 'Início',
        '/produtos.php'     => 'Produtos',
        '/busca.php'        => 'Busca',
        '/contato.php'      => 'Contato',
        '/api/produtos.php' => 'API JSON',
        '/lenta.php'        => 'Página lenta',
        '/erro.php'         => 'Erro 500',
        '/nao-existe.php'   => 'Página 404',
        '/testes.php'       => 'Testes',
    );
}

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function page_start($title)
{
    $current = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> · Site de exemplo</title>
<style>
  body { margin: 0; font: 15px/1.5 system-ui, "Segoe UI", sans-serif; color: #1d2330; background: #f6f7f9; }
  nav { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; padding: 10px 20px; background: #1d2330; }
  nav a { color: #c9d1de; text-decoration: none; padding: 6px 10px; border-radius: 6px; font-size: 14px; }
  nav a:hover { background: #2c3445; color: #fff; }
  nav a.active { background: #5b8def; color: #fff; }
  nav .brand { color: #fff; font-weight: 600; margin-right: 10px; }
  nav .spacer { flex: 1; }
  nav .panel { border: 1px solid #5b8def; color: #9fbcf7; }
  main { max-width: 900px; margin: 0 auto; padding: 24px 20px 60px; }
  .card { background: #fff; border: 1px solid #e2e5ea; border-radius: 10px; padding: 18px; margin: 14px 0; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
  .tag { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 99px; background: #eef2f8; color: #4a5568; }
  .tag.warn { background: #fdf3dc; color: #8a5a00; } .tag.err { background: #fde4e4; color: #a12828; }
  input, textarea, button { font: inherit; padding: 7px 10px; border: 1px solid #cdd3dc; border-radius: 6px; }
  button { background: #5b8def; color: #fff; border-color: #5b8def; cursor: pointer; }
  table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eceef2; }
  .muted { color: #6b7385; } footer { color: #8a92a3; font-size: 13px; margin-top: 30px; }
  a { color: #3767d6; }
</style>
</head>
<body>
<nav>
  <span class="brand">Site exemplo</span>
  <?php foreach (nav_links() as $href => $label): ?>
    <a href="<?php echo e($href); ?>" class="<?php echo $current === $href ? 'active' : ''; ?>"><?php echo e($label); ?></a>
  <?php endforeach; ?>
  <span class="spacer"></span>
  <a class="panel" href="<?php echo PANEL_URL; ?>" target="_blank">Painel ↗</a>
</nav>
<main>
<h1><?php echo e($title); ?></h1>
<?php
}

function page_end()
{
    ?>
<footer>PHP <?php echo PHP_VERSION; ?> · gerado em <?php echo date('d/m/Y H:i:s'); ?> ·
  modo de simulação: <b><?php echo e(chaos_mode()); ?></b> (<a href="/demo/chaos.php">mudar</a>)</footer>
</main>
</body>
</html>
<?php
}
