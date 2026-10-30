<?php
/**
 * Entrega ao painel os traces gravados, de forma incremental.
 * GET ?cursor=AAAAMMDD-HH:offset  ->  {"cursor": "...", "server": "...", "traces": [ {resourceSpans...}, ... ]}
 * Sem cursor, começa no arquivo da hora atual. O painel guarda "cursor" e manda de volta.
 * Também apaga arquivos mais antigos que retention_hours.
 */

require __DIR__ . '/auth.php';

$dir = Monitor::siteDir();
$maxLines = 500;
$maxBytes = 4 * 1024 * 1024;

$files = glob($dir . '/traces-*.jsonl') ?: array();
sort($files);

$limit = date('Ymd-H', time() - Monitor::config('retention_hours', 48) * 3600);
foreach ($files as $i => $f) {
    if (substr(basename($f), 7, 11) < $limit) {
        @unlink($f);
        unset($files[$i]);
    }
}
$files = array_values($files);

$cursor = isset($_GET['cursor']) ? $_GET['cursor'] : '';
if (preg_match('/^(\d{8}-\d{2}):(\d+)$/', $cursor, $m)) {
    list(, $hour, $offset) = $m;
    $offset = (int) $offset;
} else {
    $hour = date('Ymd-H');
    $offset = 0;
}

$lines = array();
$bytes = 0;
$next = $hour . ':' . $offset;
foreach ($files as $f) {
    $fileHour = substr(basename($f), 7, 11);
    if ($fileHour < $hour) {
        continue;
    }
    $start = $fileHour === $hour ? $offset : 0;
    clearstatcache(true, $f);
    if ($start > filesize($f)) {
        $start = 0; // arquivo recriado
    }
    $fp = fopen($f, 'rb');
    fseek($fp, $start);
    $pos = $start;
    while (count($lines) < $maxLines && $bytes < $maxBytes && ($line = fgets($fp)) !== false) {
        if (substr($line, -1) !== "\n") {
            break; // linha ainda sendo escrita
        }
        $pos += strlen($line);
        $bytes += strlen($line);
        $lines[] = rtrim($line, "\r\n");
    }
    fclose($fp);
    $next = $fileHour . ':' . $pos;
    if (count($lines) >= $maxLines || $bytes >= $maxBytes) {
        break;
    }
}

echo '{"cursor":', json_encode($next), ',"server":', json_encode(Monitor::config('server_name')),
    ',"site":', json_encode(Monitor::$site), ',"traces":[', implode(',', $lines), ']}';
