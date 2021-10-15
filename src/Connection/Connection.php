<?php
declare(strict_types=1);

namespace SimpleAmqp\Connection;

use SimpleAmqp\SyncClient as Client;
use Bunny\Exception\ClientException;
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
        try {
            if($this->_client instanceof Client){
                if ($this->_client->isConnected()) {
                    $this->_client->disconnect()->done(function () {
                        $this->_client->stop();
                    });
                }
                if ($this->_client->isConnected()) {
                    $this->_client->run();
                }
            }
        }catch (Throwable $throwable){} finally {
            $this->_client = null;
        }
    }

    /**
     * @return Client
     * @throws Throwable
     */
    public function connect() : Client
    {
        try {
            if(!$this->client()->isConnected()){
                $this->client()->connect();
            }
        }catch (ClientException $exception){
            $this->_client = null;
            $this->client()->connect();
        }catch (Throwable $throwable){
            throw $throwable;
        }finally {
            return $this->_client;
        }
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