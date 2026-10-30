<?php
namespace Monitor\Zf2;

use Doctrine\DBAL\Logging\SQLLogger as DoctrineSqlLogger;

/**
 * Logger do Doctrine DBAL: cada consulta executada vira um span "db" no monitor, com o SQL normalizado.
 * O DBAL não chama stopQuery() quando a consulta lança exceção; a pendente é fechada como falha
 * na próxima consulta ou no fim da requisição.
 */
class SqlLogger implements DoctrineSqlLogger
{
    private $target;
    private $pending;

    public function __construct($target)
    {
        $this->target = $target;
        $self = $this;
        \Monitor::$finalizers[] = function () use ($self) {
            $self->closePending('sem retorno: consulta lançou exceção');
        };
    }

    public function startQuery($sql, array $params = null, array $types = null)
    {
        $this->closePending('sem retorno: consulta lançou exceção');
        $this->pending = array(\Monitor::normalizeSql($sql), microtime(true));
    }

    public function stopQuery()
    {
        if ($this->pending) {
            \Monitor::record('db', $this->target, $this->pending[0], $this->pending[1]);
            $this->pending = null;
        }
    }

    public function closePending($error)
    {
        if ($this->pending) {
            \Monitor::record('db', $this->target, $this->pending[0], $this->pending[1], false, $error);
            $this->pending = null;
        }
    }
}
