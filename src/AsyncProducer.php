<?php
declare(strict_types=1);

namespace SimpleAmqp;

use Bunny\Channel;
use React\Promise\Promise;
use SimpleAmqp\Connection\AsyncConnection;
use React\Promise\PromiseInterface;
use SimpleAmqp\Client\AsyncClient;

class AsyncProducer extends AsyncConnection {
    protected $_channel;

    /**
     * @param AbstractMessage $abstractMessage
     * @param bool $close
     * @return bool|PromiseInterface
     */
    public function produce(AbstractMessage $abstractMessage, bool $close = true) : PromiseInterface
    {
        if ($this->_getChannel()){
            return $this->_channel->publish(
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
        } else {
            return $this->client()->connect()->then(
                function (AsyncClient $client){
                    return $client->channel()->then(function (Channel $channel){
                        $this->_setChannel($channel);
                        return $channel;
                    },function (Throwable $throwable){
                        $this->_setChannel();
                        if($this->_errorCallback){
                            call_user_func($this->_errorCallback, $throwable, $this);
                        }
                        $this->close(true, $throwable);
                    });
                },
                function (Throwable $throwable){
                    $this->_setChannel();
                    if($this->_errorCallback){
                        call_user_func($this->_errorCallback, $throwable, $this);
                    }
                    $this->close(true, $throwable);
                }
            )->then(function (Channel $channel) use ($abstractMessage) {
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
                        $this->_setChannel($channel);
                        return $channel;
                    },
                    function (Throwable $throwable){
                        $this->_setChannel();
                        if($this->_errorCallback){
                            call_user_func($this->_errorCallback, $throwable, $this);
                        }
                        $this->close(true, $throwable);
                    }
                );
            })->then(function (Channel $channel) use ($abstractMessage) {
                return $channel->queueDeclare(
                    $abstractMessage->getQueue(),
                    $abstractMessage->isPassive(),
                    $abstractMessage->isDurable(),
                    $abstractMessage->isExclusive(),
                    $abstractMessage->isAutoDelete(),
                    $abstractMessage->isNowait(),
                    $abstractMessage->getArguments()
                )->then(
                    function () use ($channel) {
                        $this->_setChannel($channel);
                        return $channel;
                    },
                    function (Throwable $throwable){
                        $this->_setChannel();
                        if($this->_errorCallback){
                            call_user_func($this->_errorCallback, $throwable, $this);
                        }
                        $this->close(true, $throwable);
                    }
                );
            })->then(function (Channel $channel) use ($abstractMessage) {
                return $channel->queueBind(
                    $abstractMessage->getQueue(),
                    $abstractMessage->getExchange(),
                    $abstractMessage->getRoutingKey(),
                    $abstractMessage->isNowait(),
                    $abstractMessage->getArguments()
                )->then(
                    function () use ($channel) {
                        $this->_setChannel($channel);
                        return $channel;
                    },
                    function (Throwable $throwable){
                        $this->_setChannel();
                        if($this->_errorCallback){
                            call_user_func($this->_errorCallback, $throwable, $this);
                        }
                        $this->close(true, $throwable);
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
                            $this->close(true);
                        }
                        return true;
                    },
                    function (Throwable $throwable){
                        $this->_setChannel();
                        if($this->_errorCallback){
                            call_user_func($this->_errorCallback, $throwable, $this);
                        }
                        $this->close(true, $throwable);
                        return false;
                    }
                );
            });
        }
    }

    /**
     * @return Channel|null
     */
    public function _getChannel() : ?Channel
    {
        return $this->_channel;
    }

    /**
     * @param Channel|null $channel
     * @return void
     */
    public function _setChannel(?Channel $channel = null){
        $this->_channel = $channel;
    }
}
