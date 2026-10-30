<?php
/**
 * Health do servidor PHP para o painel: versão, memória, disco, OPcache, sessão e o próprio agente.
 */

require __DIR__ . '/auth.php';
$config = array('db' => Monitor::config('health_db'), 'writable' => array(Monitor::config('data_dir')));

$started = microtime(true);
$checks = array();

function run_check($name, $fn)
{
    $t = microtime(true);
    try {
        $msg = $fn();
        $status = 'ok';
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $status = 'fail';
    } catch (Error $e) {
        $msg = $e->getMessage();
        $status = 'fail';
    }
    return array(
        'name'    => $name,
        'status'  => $status,
        'ms'      => round((microtime(true) - $t) * 1000, 2),
        'message' => $msg,
    );
}

if (!empty($config['db'])) {
    $db = $config['db'];
    $checks[] = run_check('database', function () use ($db) {
        $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ));
        $pdo->query('SELECT 1');
        return 'conectado';
    });
}

foreach ($config['writable'] as $dir) {
    $checks[] = run_check('writable:' . basename($dir), function () use ($dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception("sem permissão de escrita em $dir");
        }
        return 'ok';
    });
}

if (Monitor::config('health_session_check', true)) {
// Nunca devolver session.save_path cru: com redis/memcached ele costuma levar a senha.
$checks[] = run_check('session', function () {
    $handler = ini_get('session.save_handler');
    $path = session_save_path();

    if ($handler === 'files') {
        $dir = $path === '' ? sys_get_temp_dir() : substr($path, strrpos($path, ';') === false ? 0 : strrpos($path, ';') + 1);
        if (!is_writable($dir)) {
            throw new Exception('pasta de sessões não gravável');
        }
        return 'files: gravável';
    }

    // Handlers de rede (redis, memcached...): testa só a conexão TCP com o primeiro servidor.
    $first = trim(explode(',', $path)[0]);
    $url = parse_url(strpos($first, '://') === false ? 'tcp://' . $first : $first);
    if (empty($url['host'])) {
        return "$handler: não verificado";
    }
    $port = isset($url['port']) ? $url['port'] : ($handler === 'redis' ? 6379 : 11211);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($url['host'], $port, $errno, $errstr, 2);
    if (!$fp) {
        throw new Exception("$handler {$url['host']}:$port inacessível ($errstr)");
    }
    fclose($fp);
    return "$handler {$url['host']}:$port alcançável";
});
}

$root = Monitor::config('app_root');
$diskFree = @disk_free_space($root);
$diskTotal = @disk_total_space($root);

$opcache = null;
if (function_exists('opcache_get_status')) {
    $s = @opcache_get_status(false);
    if (is_array($s)) {
        $opcache = array(
            'enabled'      => !empty($s['opcache_enabled']),
            'hit_rate'     => isset($s['opcache_statistics']['opcache_hit_rate']) ? round($s['opcache_statistics']['opcache_hit_rate'], 2) : null,
            'memory_used'  => isset($s['memory_usage']['used_memory']) ? $s['memory_usage']['used_memory'] : null,
            'memory_free'  => isset($s['memory_usage']['free_memory']) ? $s['memory_usage']['free_memory'] : null,
        );
    }
}

$load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

$failed = 0;
foreach ($checks as $c) {
    if ($c['status'] !== 'ok') {
        $failed++;
    }
}

echo json_encode(array(
    'status'       => $failed === 0 ? 'ok' : 'degraded',
    'time'         => date('c'),
    'php'          => array(
        'version'      => PHP_VERSION,
        'sapi'         => PHP_SAPI,
        'os'           => PHP_OS,
        'memory_limit' => ini_get('memory_limit'),
        'charset'      => ini_get('default_charset'),
        'xdebug'       => extension_loaded('xdebug'),
        'max_exec'     => (int) ini_get('max_execution_time'),
        'extensions'   => count(get_loaded_extensions()),
    ),
    'memory'       => array(
        'usage' => memory_get_usage(true),
        'peak'  => memory_get_peak_usage(true),
    ),
    'disk'         => array(
        'free'  => $diskFree === false ? null : $diskFree,
        'total' => $diskTotal === false ? null : $diskTotal,
    ),
    'load'         => $load === false ? null : $load,
    'opcache'      => $opcache,
    'checks'       => $checks,
    'agent'        => array(
        'version'     => Monitor::VERSION,
        'server'      => Monitor::config('server_name'),
        'site'        => Monitor::$site,
        'sample_rate' => Monitor::config('sample_rate'),
        'slow_ms'     => Monitor::config('slow_ms'),
    ),
    'duration_ms'  => round((microtime(true) - $started) * 1000, 2),
));
