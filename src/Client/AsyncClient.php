<?php
namespace SimpleAmqp\Client;

use Bunny\AbstractClient;
use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use Bunny\Protocol\HeartbeatFrame;
use Bunny\Protocol\MethodConnectionStartFrame;
use Bunny\Protocol\MethodConnectionTuneFrame;
use Kernel\AbstractProcess;
use Psr\Log\LoggerInterface;
use React\Promise;
use Workerman\Events\EventInterface;
use Workerman\Lib\Timer;

class AsyncClient extends \Bunny\Async\Client
{
    protected $name;

    /**
     * AsyncClient constructor.
     * @param array $options
     * @param string $name
     * @param LoggerInterface|null $log
     */
    public function __construct(array $options = [], string $name = 'default', LoggerInterface $log = null)
    {
        $options['async'] =  true;
        $this->name = $name;
        $this->eventLoop = AbstractProcess::$globalEvent;
        AbstractClient::__construct($options, $log);
    }

    /**
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * Asynchronously sends buffered data over the wire.
     *
     * - Calls {@link eventLoops}'s addWriteStream() with client's stream.
     * - Consecutive calls will return the same instance of promise.
     *
     * @return Promise\PromiseInterface
     */
    protected function flushWriteBuffer() : Promise\PromiseInterface
    {
        if ($this->flushWriteBufferPromise) {
            return $this->flushWriteBufferPromise;

        } else {
            $deferred = new Promise\Deferred();

            $this->eventLoop->add($this->getStream(), EventInterface::EV_WRITE, function ($stream) use ($deferred) {
                try {
                    $this->write();

                    if ($this->writeBuffer->isEmpty()) {
                        $this->eventLoop->del($stream, EventInterface::EV_WRITE);
                        $this->flushWriteBufferPromise = null;
                        $deferred->resolve(true);
                    }

                } catch (\Exception $e) {
                    $this->eventLoop->del($stream, EventInterface::EV_WRITE);
                    $this->flushWriteBufferPromise = null;
                    $deferred->reject($e);
                }
            });

            return $this->flushWriteBufferPromise = $deferred->promise();
        }
    }

    /**
     * Connects to AMQP server.
     *
     * Calling connect() multiple times will result in error.
     *
     * @return Promise\PromiseInterface
     */
    public function connect(): Promise\PromiseInterface
    {
        if ($this->state !== ClientStateEnum::NOT_CONNECTED) {
            return Promise\reject(new ClientException('Client already connected/connecting.'));
        }

        $this->state = ClientStateEnum::CONNECTING;
        $this->writer->appendProtocolHeader($this->writeBuffer);

        try {
            $this->eventLoop->add($this->getStream(), EventInterface::EV_READ, [$this, 'onDataAvailable']);
        } catch (\Exception $e) {
            return Promise\reject($e);
        }

        return $this->flushWriteBuffer()->then(function () {
            return $this->awaitConnectionStart();

        })->then(function (MethodConnectionStartFrame $start) {
            return $this->authResponse($start);

        })->then(function () {
            return $this->awaitConnectionTune();

        })->then(function (MethodConnectionTuneFrame $tune) {
            $this->frameMax = $tune->frameMax;
            if ($tune->channelMax > 0) {
                $this->channelMax = $tune->channelMax;
            }
            return $this->connectionTuneOk($tune->channelMax, $tune->frameMax, $this->options['heartbeat']);

        })->then(function () {
            return $this->connectionOpen($this->options['vhost']);

        })->then(function () {
            if(!$this->heartbeatTimer){
                $this->heartbeatTimer = Timer::add(
                    isset($this->options['heartbeat']) ? $this->options['heartbeat'] : 60,
                    [$this, 'onHeartbeat'],
                    null,
                    true
                );
            }
            $this->state = ClientStateEnum::CONNECTED;
            return $this;
        });
    }

    /**
     * Disconnects client from server.
     *
     * - Calling disconnect() if client is not connected will result in error.
     * - Calling disconnect() multiple times will result in the same promise.
     *
     * @param int $replyCode
     * @param string $replyText
     * @return Promise\PromiseInterface
     */
    public function disconnect($replyCode = 0, $replyText = ''): Promise\PromiseInterface
    {
        if ($this->state === ClientStateEnum::DISCONNECTING) {
            return $this->disconnectPromise;
        }

        if ($this->state !== ClientStateEnum::CONNECTED) {
            return Promise\reject(new ClientException('Client is not connected.'));
        }

        $this->state = ClientStateEnum::DISCONNECTING;

        $promises = [];

        if ($replyCode === 0) {
            foreach ($this->channels as $channel) {
                $promises[] = $channel->close($replyCode, $replyText);
            }
        }

        if ($this->heartbeatTimer) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        return $this->disconnectPromise = Promise\all($promises)->then(function () use ($replyCode, $replyText) {
            if (!empty($this->channels)) {
                throw new \LogicException('All channels have to be closed by now.');
            }

            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () {
            $this->eventLoop->del($this->getStream(), EventInterface::EV_READ);
            $this->closeStream();
            $this->init();
            return $this;
        });
    }


    /**
     * Callback when heartbeat timer timed out.
     */
    public function onHeartbeat(): void
    {
        $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
        $this->flushWriteBuffer()->then(
            function () {
                if (is_callable(
                    isset($this->options['heartbeat_callback'])
                        ? $this->options['heartbeat_callback']
                        : null
                )) {
                    ($this->options['heartbeat_callback'])($this);
                }
            },
            function (\Throwable $throwable){
                if($this->log){
                    $this->log->notice(
                        'OnHeartbeatFailed',
                        [
                            $throwable->getMessage(),
                            $throwable->getCode(),
                            $throwable->getFile(),
                            $throwable->getLine()
                        ]
                    );
                }
            });
    }

}
