<?php
namespace Monitor\Zf2;

use Zend\ServiceManager\DelegatorFactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Delegator de "doctrine.connection.<nome>": liga um SqlLogger à conexão.
 *
 * As conexões do DoctrineORMModule usam por padrão a configuração DBAL compartilhada "orm_default",
 * então um logger por configuração não distinguiria os bancos. Aqui cada conexão recebe uma cópia
 * da própria configuração com o seu logger (a conexão ainda não abriu nesse momento).
 * O alvo do span vem dos parâmetros da conexão: "sqlsrv servidor/banco" (nunca usuário ou senha).
 *
 * Config: 'service_manager' => ['delegators' => ['doctrine.connection.orm_x' => ['Monitor\Zf2\ConnectionDelegator']]]
 */
class ConnectionDelegator implements DelegatorFactoryInterface
{
    const PREFIX = 'doctrine.connection.';

    public function createDelegatorWithName(ServiceLocatorInterface $services, $name, $requestedName, $callback)
    {
        $connection = $callback();

        $config = clone $connection->getConfiguration();
        $config->setSQLLogger(new SqlLogger(self::target($services, substr($requestedName, strlen(self::PREFIX)))));
        $prop = new \ReflectionProperty('Doctrine\DBAL\Connection', '_config');
        $prop->setAccessible(true);
        $prop->setValue($connection, $config);

        return $connection;
    }

    private static function target(ServiceLocatorInterface $services, $name)
    {
        $config = $services->get('config');
        $conn = isset($config['doctrine']['connection'][$name]) ? $config['doctrine']['connection'][$name] : array();
        $params = isset($conn['params']) ? $conn['params'] : array();

        $driver = isset($conn['driverClass']) ? $conn['driverClass'] : '';
        $driver = stripos($driver, 'SQLSrv') !== false ? 'sqlsrv' : (stripos($driver, 'PgSql') !== false ? 'pgsql' : 'db');
        $host = isset($params['host']) ? $params['host'] : '?';
        $db = isset($params['dbname']) ? '/' . $params['dbname'] : '';

        return "$driver $host$db";
    }
}
