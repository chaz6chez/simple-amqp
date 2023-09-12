<?php
declare(strict_types=1);

namespace SimpleAmqp\Client;

use Bunny\Client;
use Bunny\ClientStateEnum;
use Bunny\Protocol\HeartbeatFrame;
use Workerman\Timer;

class SyncClient extends Client {

    protected ?int $heartbeatTimer = null;

    public function __destruct()
    {
        try {
            if($this->heartbeatTimer){
                Timer::del($this->heartbeatTimer);
            }
            parent::__destruct();
        }catch (\Throwable $throwable){}
    }

    /**
     * @return SyncClient
     * @throws \Exception
     * @Time 2023/9/12 14:37
     * @author sunsgne
     */
    public function connect()
    {
        $result = parent::connect();
        /** 连接成功，开启心跳 */
        if ($result->state === ClientStateEnum::CONNECTED){
            $this->heartbeatTimer = Timer::add($this->options['heartbeat'] ?? 60, [$this, 'onHeartbeat']);
        }
        return $result;
    }


    /**
     * Callback when heartbeat timer timed out.
     * @return void
     * @Time 2023/9/12 14:22
     * @author sunsgne
     */
    public function onHeartbeat()
    {
        $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
        $this->flushWriteBuffer();

        if (is_callable($this->options['heartbeat_callback'] ?? null)) {
            $heartbeat_status = $this->state === ClientStateEnum::CONNECTED ? true : false;
            $this->options['heartbeat_callback']($this , $heartbeat_status);
        }
    }
}
