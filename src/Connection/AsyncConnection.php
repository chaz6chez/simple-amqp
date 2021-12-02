<?php
declare(strict_types=1);

namespace SimpleAmqp\Connection;

use Psr\Log\LoggerInterface;
use SimpleAmqp\Client\SyncClient as Client;
use SimpleAmqp\Client\AsyncClient;

class AsyncConnection extends AbstractConnection {
    protected $_error;

    /**
     * 该函数需要EventLoop启动之后才可调用
     * @return AsyncClient
     */
    public function client(): AsyncClient
    {
        if(!$this->_client instanceof AsyncClient){
            $this->_client = new AsyncClient($this->getConfig(),$this->getName(),$this->_logger);
        }
        return $this->_client;
    }

    public function getError() : \Throwable{
        return $this->_error;
    }

    public function checker() : bool
    {
        $client = new Client($this->getConfig());
        $res = true;
        try {
            $client->connect();
        }catch (\Throwable $throwable){
            $res = false;
        } finally {
            $client = null;
            $this->_error = isset($throwable) ? $throwable : null;
            return $res;
        }
    }

    public function close(): void
    {
        if(
            $this->_client instanceof AsyncClient and
            $this->_client->isConnected()
        ){
            $this->_client->disconnect();
        }
        $this->_client = null;
    }

    /**
     * 使用该函数之前请使用{@see AsyncConnection::checker()}先检测连接是否正常
     *
     * @see AsyncConnection::client()
     * @return AsyncClient
     */
    public function connect() : AsyncClient
    {
        return $this->client();
    }

    public function error(\Throwable $throwable): bool
    {
        if($this->getLogger()){
            $this->getLogger()->error(
                "[Async]{$throwable->getCode()}:{$throwable->getMessage()}",
                $throwable->getTrace()
            );
        }
        return false;
    }
}