<?php
namespace Monitor\Zf2;

use Zend\ServiceManager\DelegatorFactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Medição do Redis:
 *  - wrapCommonRedis(): troca o Predis\Client estático de Common\Redis por um proxy medido.
 *  - como delegator do serviço de cache (Zend\Cache com adaptador redis): mede cada operação pelos
 *    eventos "<operação>.pre/.post/.exception" que o próprio Zend\Cache dispara.
 */
class RedisWrapper implements DelegatorFactoryInterface
{
    public static function target(ServiceLocatorInterface $services)
    {
        $config = $services->get('config');
        $r = isset($config['redis']) ? $config['redis'] : array();
        return (isset($r['host']) ? $r['host'] : 'redis') . (isset($r['port']) ? ':' . $r['port'] : '');
    }

    public static function wrapCommonRedis(ServiceLocatorInterface $services)
    {
        if (!class_exists('Common\Redis\Redis', true)) {
            return;
        }
        $prop = new \ReflectionProperty('Common\Redis\Redis', 'client');
        $prop->setAccessible(true);
        $client = $prop->getValue();
        if ($client && !$client instanceof \MonitorProxy) {
            $prop->setValue(\Monitor::wrap($client, 'redis', self::target($services)));
        }
    }

    public function createDelegatorWithName(ServiceLocatorInterface $services, $name, $requestedName, $callback)
    {
        $service = $callback();
        $storage = self::findStorage($service);
        if (!$storage) {
            return $service;
        }
        $target = self::target($services) . ' (cache)';
        $started = array();

        $events = $storage->getEventManager();
        $events->attach('*', function ($e) use ($target, &$started) {
            $name = $e->getName();
            $dot = strrpos($name, '.');
            if ($dot === false) {
                return;
            }
            $op = substr($name, 0, $dot);
            $phase = substr($name, $dot + 1);
            if ($phase === 'pre') {
                $started[$op][] = microtime(true);
            } elseif (($phase === 'post' || $phase === 'exception') && !empty($started[$op])) {
                $t = array_pop($started[$op]);
                $error = null;
                if ($phase === 'exception' && method_exists($e, 'getException')) {
                    $error = $e->getException()->getMessage();
                }
                \Monitor::record('redis', $target, $op, $t, $phase === 'post', $error);
            }
        }, 10000);

        return $service;
    }

    /**
     * O serviço pode ser o próprio Zend\Cache ou uma classe do site que o guarda numa propriedade
     * (ex.: um CacheService do próprio site). Devolve o armazenamento que dispara os eventos.
     */
    private static function findStorage($service)
    {
        if ($service instanceof \Zend\Cache\Storage\StorageInterface && method_exists($service, 'getEventManager')) {
            return $service;
        }
        if (!is_object($service)) {
            return null;
        }
        foreach ((new \ReflectionObject($service))->getProperties() as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($service);
            if ($value instanceof \Zend\Cache\Storage\StorageInterface && method_exists($value, 'getEventManager')) {
                return $value;
            }
        }
        return null;
    }
}
