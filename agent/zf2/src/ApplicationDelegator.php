<?php
namespace Monitor\Zf2;

use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\DelegatorFactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Envolve o serviço "Application" do ZF2 (único ponto configurado no site) e daí liga o resto:
 *  - nome da requisição pelo controller/action (rotas genéricas como /[:controller[/:action]] viram uma só);
 *  - exceções que o ZF2 captura e transforma em página de erro (não chegam ao error_get_last);
 *  - delegators de todas as conexões do Doctrine (doctrine.connection.*) e dos serviços de cache;
 *  - Zend\Http\Client medido (HttpClientInterceptor) e Redis da classe Common\Redis, se existir.
 *
 * Config (monitor.local.php): 'service_manager' => ['delegators' => ['Application' => [ApplicationDelegator]]]
 */
class ApplicationDelegator implements DelegatorFactoryInterface
{
    public function createDelegatorWithName(ServiceLocatorInterface $services, $name, $requestedName, $callback)
    {
        $app = $callback();
        $events = $app->getEventManager();

        // Aqui o autoloader do Composer já existe; o interceptor precisa ficar na frente dele.
        HttpClientInterceptor::register(\Monitor::siteDir() . '/generated');

        $config = $services->get('config');
        if (method_exists($services, 'addDelegator')) {
            $connections = isset($config['doctrine']['connection']) ? array_keys($config['doctrine']['connection']) : array();
            foreach ($connections as $connection) {
                $services->addDelegator('doctrine.connection.' . $connection, 'Monitor\Zf2\ConnectionDelegator');
            }
            $caches = (array) \Monitor::config('zf2_cache_services', array());
            if (isset($config['caches']) && is_array($config['caches'])) {
                $caches = array_merge($caches, array_keys($config['caches'])); // StorageCacheAbstractServiceFactory
            }
            foreach (array_unique($caches) as $cache) {
                $services->addDelegator($cache, 'Monitor\Zf2\RedisWrapper');
            }
        }

        $events->attach(MvcEvent::EVENT_ROUTE, function (MvcEvent $e) {
            $match = $e->getRouteMatch();
            if (!$match) {
                return;
            }
            $controller = (string) $match->getParam('controller', '?');
            $controller = str_replace('\\Controller\\', '\\', $controller);
            \Monitor::$route = $controller . '::' . $match->getParam('action', 'index');
        }, -10000);

        // Só exceções viram erro; "error-router-no-match" etc. já aparecem como 404.
        $onError = function (MvcEvent $e) {
            $ex = $e->getParam('exception');
            if ($ex) {
                \Monitor::$error = get_class($ex) . ': ' . $ex->getMessage();
            }
        };
        $events->attach(MvcEvent::EVENT_DISPATCH_ERROR, $onError, -10000);
        $events->attach(MvcEvent::EVENT_RENDER_ERROR, $onError, -10000);

        // Depois do onBootstrap dos módulos (que criam o cliente Redis).
        $events->attach(MvcEvent::EVENT_BOOTSTRAP, function (MvcEvent $e) {
            RedisWrapper::wrapCommonRedis($e->getApplication()->getServiceManager());
        }, -10000);

        return $app;
    }
}
