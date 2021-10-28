<?php
declare(strict_types=1);

namespace SimpleAmqp;

use Bunny\Channel;
use Throwable;
use SimpleAmqp\Connection\Connection;

class Producer extends Connection {

    protected $_channel;

    public function produce(AbstractMessage $abstractMessage, bool $close = true) : bool
    {
        try {
            $this->_getChannel()->exchangeDeclare(
                $abstractMessage->getExchange(),
                $abstractMessage->getExchangeType(),
                $abstractMessage->isPassive(),
                $abstractMessage->isDurable(),
                $abstractMessage->isAutoDelete(),
                $abstractMessage->isInternal(),
                $abstractMessage->isNowait(),
                $abstractMessage->getArguments()
            );
            $this->_getChannel()->queueDeclare(
                $abstractMessage->getQueue(),
                $abstractMessage->isPassive(),
                $abstractMessage->isDurable(),
                $abstractMessage->isExclusive(),
                $abstractMessage->isAutoDelete(),
                $abstractMessage->isNowait(),
                $abstractMessage->getArguments()
            );
            $this->_getChannel()->queueBind(
                $abstractMessage->getQueue(),
                $abstractMessage->getExchange(),
                $abstractMessage->getRoutingKey(),
                $abstractMessage->isNowait(),
                $abstractMessage->getArguments()
            );
            return $this->_getChannel()->publish(
                $abstractMessage->getBody(),
                $abstractMessage->getHeaders(),
                $abstractMessage->getExchange(),
                $abstractMessage->getRoutingKey(),
                $abstractMessage->isMandatory(),
                $abstractMessage->isImmediate()
            );
        }catch (Throwable $throwable){
            return $this->error($throwable);
        } finally {
            if($close){
                $this->_setChannel();
                $this->close();
            }
        }
    }

    /**
     * @return Channel|null
     * @throws Throwable
     */
    protected function _getChannel() : ?Channel
    {
        if(!$this->_channel instanceof Channel){
            $this->_setChannel($this->connect()->channel());
        }
        return $this->_channel;
    }

    protected function _setChannel(?Channel $channel = null){
        $this->_channel = $channel;
    }
}