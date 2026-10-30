<?php
// Router do servidor embutido (php -S localhost:8080 -t site site/router.php).
// Faz o papel do Apache: carrega o agente, executa o .php certo e responde 404 para o resto.
// O mesmo loader que vai nos sites reais (aqui com MONITOR_HOME apontando para ../agent).
putenv('MONITOR_HOME=' . dirname(__DIR__) . '/agent');
require dirname(__DIR__) . '/agent/loader.php';

$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath(__DIR__ . $path);

if ($file !== false && is_dir($file)) {
    $file = realpath($file . '/index.php');
}

$inside = $file !== false && strpos($file, __DIR__) === 0;
$blocked = strpos($path, '/data/') === 0 || strpos($path, '/includes/') === 0 || $file === __FILE__;

if (!$inside || $blocked || !is_file($file)) {
    require __DIR__ . '/includes/404.php';
    return true;
}

if (substr($file, -4) !== '.php') {
    return false; // arquivo estático: o servidor embutido entrega
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = substr($file, strlen(__DIR__));
chdir(dirname($file));
require $file;
return true;
