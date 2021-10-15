<?php
declare(strict_types=1);

namespace SimpleAmqp;

use Bunny\Channel;
use SimpleAmqp\Connection\AsyncConnection;
use React\Promise\PromiseInterface;
use Workerman\RabbitMQ\Client;

class AsyncProducer extends AsyncConnection {
    protected $_channel;

    /**
     * @param AbstractMessage $abstractMessage
     * @param bool $close
     * @return bool|PromiseInterface
     */
    public function produce(AbstractMessage $abstractMessage, bool $close = true) : PromiseInterface
    {
        if($this->client()->isConnected()){
            return $this->_getChannel()->publish(
                $abstractMessage->getBody(),
                $abstractMessage->getHeaders(),
                $abstractMessage->getExchange(),
                $abstractMessage->getRoutingKey(),
                $abstractMessage->isMandatory(),
                $abstractMessage->isImmediate()
            )->then(
                function () use ($close){
                    if($close){
                        $this->_setChannel();
                        $this->close();
                    }
                    return true;
                },
                function (\Throwable $throwable){
                    $this->_setChannel();
                    $this->close();
                    return $this->error($throwable);
                }
            );
        } else{
            return $this->client()->connect()->then(function (Client $client){
                return $client->channel()->then(function (Channel $channel){
                    $this->_setChannel($channel);
                    return $channel;
                },function (\Throwable $throwable){
                    $this->_setChannel();
                    $this->close();
                    return $this->error($throwable);
                });
            },function (\Throwable $throwable){
                $this->_setChannel();
                $this->close();
                return $this->error($throwable);
            })->then(function (Channel $channel) use ($abstractMessage) {
                return $channel->exchangeDeclare(
                    $abstractMessage->getExchange(),
                    $abstractMessage->getExchangeType(),
                    $abstractMessage->isPassive(),
                    $abstractMessage->isDurable(),
                    $abstractMessage->isAutoDelete(),
                    $abstractMessage->isInternal(),
                    $abstractMessage->isNowait(),
                    $abstractMessage->getArguments()
                )->then(
                    function () use ($channel) {
                        return $channel;
                    },
                    function (\Throwable $throwable){
                        $this->_setChannel();
                        $this->close();
                        return $this->error($throwable);
                    }
                );
            })->then(function (Channel $channel) use ($abstractMessage, $close) {
                return $channel->publish(
                    $abstractMessage->getBody(),
                    $abstractMessage->getHeaders(),
                    $abstractMessage->getExchange(),
                    $abstractMessage->getRoutingKey(),
                    $abstractMessage->isMandatory(),
                    $abstractMessage->isImmediate()
                )->then(
                    function () use ($close){
                        if($close){
                            $this->_setChannel();
                            $this->close();
                        }
                        return true;
                    },
                    function (\Throwable $throwable){
                        $this->_setChannel();
                        $this->close();
                        return $this->error($throwable);
                    }
                );
            });
        }
    }

    protected function _getChannel() : ?Channel
    {
        return $this->_channel;
    }

    protected function _setChannel(?Channel $channel = null){
        $this->_channel = $channel;
    }
}