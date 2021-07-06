<?php
declare(strict_types=1);

namespace SimpleAmqp\Connection;

use Bunny\Client;
use Utils\Tools;

class Connection extends AbstractConnection {
    public function client(): Client
    {
        if(!$this->_client instanceof Client){
            $this->_client = new Client($this->getConfig());
        }
        return $this->_client;
    }

    public function close(): void
    {
        $this->_client = null;
    }

    /**
     * @return Client
     * @throws \Throwable
     */
    public function connect() : Client
    {
        try {
            if(!$this->client()->isConnected()){
                $this->client()->connect();
            }
            return $this->_client;
        }catch (\Throwable $throwable){
            $this->error($throwable);
            throw $throwable;
        }
    }

    public function error(\Throwable $throwable): bool
    {
        if(DEBUG) dump($throwable);
        Tools::log('rabbitmq',$throwable->getMessage(),runtime_path() . '/log');
    }
}