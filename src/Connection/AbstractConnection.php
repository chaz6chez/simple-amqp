<?php
declare(strict_types=1);

namespace SimpleAmqp\Connection;

use Psr\Log\LoggerInterface;

abstract class AbstractConnection {

    protected $_client;
    protected $_logger;
    protected $_config;

    public function __construct()
    {
        $this->_config = C('mq.amqp');
    }

    final public function getConfig() : array
    {
        return $this->_config;
    }

    final public function getLogger() : LoggerInterface
    {
        return $this->_logger;
    }

    final public function setLogger(LoggerInterface $logger): AbstractConnection
    {
        $this->_logger = $logger;
        return $this;
    }

    abstract public function client();
    abstract public function close() : void;
    abstract public function error(\Throwable $throwable) : bool;

    abstract function connect();

    public function reconnect() : void
    {
        $this->close();
        $this->connect();
    }
}