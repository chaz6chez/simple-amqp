<?php
declare(strict_types=1);

namespace SimpleAmqp;

use Bunny\Channel;
use Bunny\Message;
use Bunny\Protocol\MethodBasicConsumeOkFrame;
use SimpleAmqp\Connection\AsyncConnection;
use SimpleAmqp\Client\AsyncClient;

class Consumer extends AsyncConnection {

    public function consume(AbstractMessage $abstractMessage) : void
    {
        $this->client()->connect()->then(
            function (AsyncClient $client){
                return $client->channel()->then(function (Channel $channel){
                    return $channel;
                },function (\Throwable $throwable){
                    $this->close();
                    $this->error($throwable);
                });
            },
            function (\Throwable $throwable){
                $this->close();
                $this->error($throwable);
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
                    return $channel;
                },
                function (\Throwable $throwable){
                    $this->error($throwable);
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
                    return $channel;
                },
                function (\Throwable $throwable){
                    $this->error($throwable);
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
                    return $channel;
                },
                function (\Throwable $throwable){
                    $this->error($throwable);
                }
            );
        })->then(function (Channel $channel) use ($abstractMessage) {
            return $channel->qos(
                $abstractMessage->getPrefetchSize(),
                $abstractMessage->getPrefetchCount(),
                $abstractMessage->isGlobal()
            )->then(
                function () use ($channel) {
                    return $channel;
                },
                function (\Throwable $throwable){
                    $this->error($throwable);
                }
            );
        })->then(function (Channel $channel) use ($abstractMessage) {
            //Waiting for messages
            $channel->consume(
                function (Message $message, Channel $channel, AsyncClient $client) use ($abstractMessage) {
                    $tag = ($abstractMessage->getCallback())($message, $channel, $client);
                    switch ($tag) {
                        case Constants::ACK:
                            $res = $channel->ack($message);
                            break;
                        case Constants::NACK:
                            $res = $channel->nack($message);
                            break;
                        case Constants::REQUEUE:
                        default:
                            $res = $channel->reject($message);
                            break;
                    }
                    $res->then(
                        function (){

                        },
                        function (\Throwable $throwable){
                            $this->error($throwable);
                        }
                    );
            },
                $abstractMessage->getQueue(),
                $abstractMessage->getConsumerTag(),
                $abstractMessage->isNoLocal(),
                $abstractMessage->isNoAck(),
                $abstractMessage->isExclusive(),
                $abstractMessage->isNowait(),
                $abstractMessage->getArguments()
            )->then(
                function (MethodBasicConsumeOkFrame $ok){

                },
                function (\Throwable $throwable){
                    $this->error($throwable);
                }
            );
        });
    }
}