<?php
declare(strict_types=1);

namespace SimpleAmqp\Connection;

use Bunny\Client;
use Throwable;

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
        if(
            $this->_client instanceof Client and
            $this->_client->isConnected()
        ){
            $this->_client->disconnect();
        }
        $this->_client = null;
    }

    /**
     * @return Client
     * @throws Throwable
     */
    public function connect() : Client
    {
        if(!$this->client()->isConnected()){
            $this->client()->connect();
        }
        return $this->_client;
    }

    public function error(\Throwable $throwable): bool
    {
        if($this->getLogger()){
            $this->getLogger()->error(
                "[Sync]{$throwable->getCode()}:{$throwable->getMessage()}",
                $throwable->getTrace()
            );
        }
        return false;
    }
}