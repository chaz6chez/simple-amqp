<?php
declare(strict_types=1);

namespace SimpleAmqp;

use Bunny\Channel;
use Bunny\Message;
use Kernel\Utils\Instance;
use React\Promise\PromiseInterface;
use Workerman\RabbitMQ\Client;
use SimpleAmqp\Message as BaseMessage;

abstract class Builder extends Instance {
    protected $_exchange_name;
    protected $_exchange_type;
    protected $_queue_name;
    protected $_routing_key;
    protected $_consumer_tag;
    protected $_message;

    protected function _initConfig()
    {
        $this->_message = $this->_getAbstractMessage();
        $this->_message->setQueue($this->_queue_name);
        $this->_message->setExchange($this->_exchange_name);
        $this->_message->setExchangeType($this->_exchange_type);
        $this->_message->setRoutingKey($this->_routing_key);
        if($this->_consumer_tag){
            $this->_message->setConsumerTag($this->_consumer_tag);
        }
        $this->_message->setCallback([$this,'callback']);
    }

    protected function _getAbstractMessage() : AbstractMessage
    {
        if(!$this->_message instanceof AbstractMessage){
            $this->_message = make($this->_message ?? BaseMessage::class);
        }
        return $this->_message;
    }

    final public function consumer() : Consumer
    {
        return Co()->get(Consumer::class);
    }

    final public function consume() : void
    {
        $this->consumer()->consume($this->_getAbstractMessage());
    }

    final public function producer() : Producer
    {
        return Co()->get(Producer::class);
    }

    final public function asyncProducer() : AsyncProducer
    {
        return Co()->get(AsyncProducer::class);
    }

    final public function produce(string $data, bool $close = true) : bool
    {
        $this->_getAbstractMessage()->setBody($data);
        return $this->producer()->produce($this->_getAbstractMessage(), $close);
    }

    /**
     * @param string $data
     * @param bool $close
     * @return bool|PromiseInterface
     */
    final public function asyncProduce(string $data, bool $close = true) : PromiseInterface
    {
        $this->_getAbstractMessage()->setBody($data);
        return $this->asyncProducer()->produce($this->_getAbstractMessage(), $close);
    }

    abstract public function encode($data);
    abstract public function decode($data);
    abstract public function callback(Message $message, Channel $channel, Client $client) : string;
}