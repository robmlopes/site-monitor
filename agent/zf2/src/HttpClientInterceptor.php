<?php
namespace Monitor\Zf2;

/**
 * Mede o Zend\Http\Client sem editar o site: um autoloader registrado antes do Composer carrega
 *  1) uma cópia da classe original renomeada para Zend\Http\MonitorOriginalClient (gerada em $cacheDir);
 *  2) Zend\Http\Client estendendo essa cópia, com send() medido (MonitoredHttpClient.php).
 * O arquivo original é localizado pelo próprio Composer, então funciona tanto com zend-http
 * (ZF2 2.5) quanto com o pacote monolítico zendframework/zendframework (ZF2 2.3).
 */
class HttpClientInterceptor
{
    const CLIENT = 'Zend\Http\Client';

    public static function register($cacheDir)
    {
        if (class_exists(self::CLIENT, false)) {
            return; // já carregada por alguém antes: não dá mais para trocar
        }
        $source = self::locate();
        if (!$source) {
            return;
        }
        spl_autoload_register(function ($class) use ($source, $cacheDir) {
            if ($class !== HttpClientInterceptor::CLIENT) {
                return;
            }
            $copy = $cacheDir . '/ZendHttpMonitorOriginalClient-' . md5($source) . '.php';
            if (!is_file($copy) || filemtime($copy) < filemtime($source)) {
                $code = preg_replace('/^class Client\b/m', 'class MonitorOriginalClient', file_get_contents($source), 1, $count);
                if ($count !== 1) {
                    return; // formato inesperado: deixa o Composer carregar a original
                }
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0775, true);
                }
                file_put_contents($copy . '.tmp', $code, LOCK_EX);
                @rename($copy . '.tmp', $copy);
            }
            require_once $copy;
            require_once __DIR__ . '/MonitoredHttpClient.php';
        }, true, true);
    }

    /** Pergunta ao autoloader do Composer onde está o arquivo da classe. */
    private static function locate()
    {
        foreach (spl_autoload_functions() as $fn) {
            if (is_array($fn) && is_object($fn[0]) && method_exists($fn[0], 'findFile')) {
                $file = $fn[0]->findFile(self::CLIENT);
                if ($file && is_file($file)) {
                    return realpath($file);
                }
            }
        }
        return null;
    }
}
