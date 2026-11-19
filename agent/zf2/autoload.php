<?php
// Autoloader das classes Monitor\Zf2\* (integração do agente com Zend Framework 2 + Doctrine DBAL 2.x).
spl_autoload_register(function ($class) {
    if (strpos($class, 'Monitor\\Zf2\\') !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Monitor\\Zf2\\'))) . '.php';
    // Roda no fluxo do site (o monitor.local.php pergunta class_exists): arquivo ausente ou sem permissão de leitura
    // deixa a classe indefinida, e o site segue sem a integração. Nunca require, que daria erro fatal.
    if (is_file($file) && is_readable($file)) {
        @include $file;
    }
});
