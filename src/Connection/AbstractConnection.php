<?php
declare(strict_types=1);

namespace SimpleAmqp\Connection;

use Psr\Log\LoggerInterface;

abstract class AbstractConnection {

    protected $_client;
    protected $_logger;
    protected $_config;
    protected $_name;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->_name = $name;
    }

    public function __construct()
    {
        $this->_config = C('mq.amqp');
    }

    final public function getConfig() : array
    {
        return $this->_config;
    }

    final public function getLogger() : ?LoggerInterface
    {
        return $this->_logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return static
     */
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