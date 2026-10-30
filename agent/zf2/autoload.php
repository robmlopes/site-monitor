<?php
// Autoloader das classes Monitor\Zf2\* (integração do agente com Zend Framework 2 + Doctrine DBAL 2.x).
spl_autoload_register(function ($class) {
    if (strpos($class, 'Monitor\\Zf2\\') !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Monitor\\Zf2\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
