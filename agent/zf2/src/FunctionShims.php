<?php
namespace Monitor\Zf2;

/**
 * Mede funções nativas sem editar o site: dentro de um namespace, uma chamada sem barra como
 * curl_exec($ch) procura primeiro Namespace\curl_exec(). Este gerador cria essas funções
 * ("shims") em todos os namespaces do site que chamam as funções medidas.
 *
 * Só funciona para chamadas sem barra em arquivos com namespace; \curl_exec() e código sem
 * namespace continuam chamando a nativa. Namespaces que DEFINEM uma função com o mesmo nome
 * ficam de fora (evita "cannot redeclare"). O arquivo é recriado quando apagado ou depois de
 * shims_ttl_hours.
 */
class FunctionShims
{
    /** função => corpo do shim */
    private static $shims = array(
        'curl_exec'         => 'function curl_exec($ch) { return \monitor_curl_exec($ch); }',
        'curl_setopt'       => 'function curl_setopt($ch, $option, $value) { return \monitor_curl_setopt($ch, $option, $value); }',
        'curl_setopt_array' => 'function curl_setopt_array($ch, array $options) { return \monitor_curl_setopt_array($ch, $options); }',
        'session_start'     => 'function session_start($options = array()) { return \Monitor\Zf2\FunctionShims::sessionStart($options); }',
    );

    /** Carrega os shims, gerando o arquivo se preciso. $extraNamespaces: namespaces fora de $scanDirs (ex.: vendor). */
    public static function load(array $scanDirs, $outFile, array $extraNamespaces = array())
    {
        $ttl = \Monitor::config('shims_ttl_hours', 24) * 3600;
        if (!is_file($outFile) || ($ttl > 0 && filemtime($outFile) < time() - $ttl)) {
            if (!is_dir(dirname($outFile))) {
                @mkdir(dirname($outFile), 0775, true);
            }
            // Um processo gera; os outros seguem com o arquivo existente (se houver) enquanto isso.
            $lock = @fopen($outFile . '.lock', 'c');
            if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                self::generate($scanDirs, $outFile, $extraNamespaces);
                flock($lock, LOCK_UN);
            }
            if ($lock) {
                fclose($lock);
            }
        }
        if (is_file($outFile)) {
            require_once $outFile;
        }
    }

    public static function generate(array $scanDirs, $outFile, array $extraNamespaces = array())
    {
        $found = $extraNamespaces; // namespace => [funções chamadas]
        $defined = array();        // namespace => [funções definidas pelo próprio site]
        $names = implode('|', array_keys(self::$shims));
        $calls = '/(?<![\\\\\w>:$])(?<!function )(' . $names . ')\s*\(/';
        $defs = '/function\s+&?\s*(' . $names . ')\s*\(/';

        foreach ($scanDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                if (!preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $ns)) {
                    continue;
                }
                if (preg_match_all($defs, $code, $d)) {
                    foreach ($d[1] as $fn) {
                        $defined[$ns[1]][] = $fn;
                    }
                }
                if (preg_match_all($calls, $code, $m)) {
                    foreach ($m[1] as $fn) {
                        $found[$ns[1]][] = $fn;
                    }
                }
            }
        }

        $out = "<?php\n// Gerado por Monitor\\Zf2\\FunctionShims em " . date('c') . ". Apague para gerar de novo.\n";
        ksort($found);
        foreach ($found as $ns => $fns) {
            $fns = array_unique($fns);
            // Onde há curl_exec, mede também curl_setopt(_array): é deles que vêm os cabeçalhos do site.
            if (in_array('curl_exec', $fns, true)) {
                $fns = array_unique(array_merge($fns, array('curl_setopt', 'curl_setopt_array')));
            }
            $fns = array_diff($fns, isset($defined[$ns]) ? $defined[$ns] : array());
            if (!$fns) {
                continue;
            }
            $out .= "\nnamespace $ns {\n";
            foreach ($fns as $fn) {
                $out .= "    if (!function_exists(__NAMESPACE__ . '\\\\$fn')) {\n        " . self::$shims[$fn] . "\n    }\n";
            }
            $out .= "}\n";
        }

        file_put_contents($outFile . '.tmp', $out, LOCK_EX);
        @unlink($outFile);
        @rename($outFile . '.tmp', $outFile);
    }

    /** Abertura de sessão: com save_handler redis/memcached é uma ida ao servidor (e pode esperar lock). */
    public static function sessionStart($options = array())
    {
        $handler = ini_get('session.save_handler');
        $first = trim(explode(',', (string) session_save_path())[0]);
        $url = parse_url(strpos($first, '://') === false ? 'tcp://' . $first : $first);
        $target = $handler . (empty($url['host']) ? '' : ' ' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : ''));

        $t = microtime(true);
        $ok = \session_start($options);
        \Monitor::record('session', $target, 'session_start', $t, $ok, $ok ? null : 'session_start falhou');
        return $ok;
    }
}
