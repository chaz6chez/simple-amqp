<?php
declare(strict_types=1);

namespace SimpleAmqp\Client;

use Bunny\AbstractClient;
use Bunny\Async\Client;
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

class AsyncClient extends Client
{
    /** @var string */
    protected $name;

    /** @var EventInterface */
    protected $eventLoop;

    /** @var LoggerInterface|null  */
    protected $log;

    /** @var Promise\PromiseInterface|null */
    protected $flushWriteBufferPromise;

    /** @var callable[] */
    protected $awaitCallbacks;

    /** @var Timer */
    protected $stopTimer;

    /** @var Timer */
    protected $heartbeatTimer;

    /**
     * AsyncClient constructor.
     * @param array $options
     * @param string $name
     * @param LoggerInterface|null $log
     */
    public function __construct(array $options = [], string $name = 'default', LoggerInterface $log = null)
    {
        $options['async'] = true;
        $this->name = $name;
        $this->log = $log;
        AbstractClient::__construct($options);
        $this->eventLoop = AbstractProcess::$globalEvent;
    }

    /**
     * Destructor.
     *
     * Clean shutdown = disconnect if connected.
     */
    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->disconnect();
        }
    }

    /**
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * Initializes instance.
     */
    protected function init()
    {
        parent::init();
        $this->flushWriteBufferPromise = null;
        $this->awaitCallbacks = [];
        $this->disconnectPromise = null;
    }

    /**
     * Calls {@link eventLoop}'s run() method. Processes messages for at most $maxSeconds.
     *
     * @param float $maxSeconds
     */
    public function run($maxSeconds = null)
    {
        if ($maxSeconds !== null) {
            $this->stopTimer = Timer::add($maxSeconds, function (){
                $this->stop();
            });
        }

        $this->eventLoop->loop();
    }

    /**
     * Calls {@link eventLoop}'s stop() method.
     */
    public function stop()
    {
        if ($this->stopTimer) {
            $this->stopTimer = Timer::del($this->stopTimer);
            $this->stopTimer = null;
        }

        $this->eventLoop->destroy();
    }

    /**
     * Reads data from stream to read buffer.
     */
    protected function feedReadBuffer()
    {
        throw new \LogicException("feedReadBuffer() in async client does not make sense.");
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
    public function connect() : Promise\PromiseInterface
    {
        if ($this->state !== ClientStateEnum::NOT_CONNECTED) {
            return Promise\reject(new ClientException("Client already connected/connecting."));
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
            return $this->connectionTuneOk($tune->channelMax, $tune->frameMax, $this->options["heartbeat"]);

        })->then(function () {
            return $this->connectionOpen($this->options["vhost"]);

        })->then(function () {
            $this->heartbeatTimer = Timer::add($this->options['heartbeat'], [$this, 'onHeartbeat']);

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
    public function disconnect($replyCode = 0, $replyText = '') : Promise\PromiseInterface
    {
        if ($this->state === ClientStateEnum::DISCONNECTING) {
            return $this->disconnectPromise;
        }

        if ($this->state !== ClientStateEnum::CONNECTED) {
            return Promise\reject(new ClientException("Client is not connected."));
        }

        $this->state = ClientStateEnum::DISCONNECTING;

        $promises = [];

        if ($replyCode === 0) {
            foreach ($this->channels as $channel) {
                $promises[] = $channel->close($replyCode, $replyText);
            }
        }
        else{
            foreach($this->channels as $channel){
                $this->removeChannel($channel->getChannelId());
            }
        }

        if ($this->heartbeatTimer) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        return $this->disconnectPromise = Promise\all($promises)->then(function () use ($replyCode, $replyText) {
            if (!empty($this->channels)) {
                throw new \LogicException("All channels have to be closed by now.");
            }
            if($replyCode !== 0){
                return null;
            }
            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () use ($replyCode, $replyText){
            $this->eventLoop->del($this->getStream(), EventInterface::EV_READ);
            $this->closeStream();
            $this->init();
            if($replyCode !== 0){
                AbstractProcess::stopAll(0,'(pid:' . posix_getpid() . ") {$replyCode}-{$replyText}");
            }
            return $this;
        });
    }

    /**
     * Adds callback to process incoming frames.
     *
     * Callback is passed instance of {@link \Bunny\Protocol|AbstractFrame}. If callback returns TRUE, frame is said to
     * be handled and further handlers (other await callbacks, default handler) won't be called.
     *
     * @param callable $callback
     */
    public function addAwaitCallback(callable $callback)
    {
        $this->awaitCallbacks[] = $callback;
    }

    /**
     * {@link eventLoop}'s read stream callback notifying client that data from server arrived.
     */
    public function onDataAvailable()
    {
        $this->read();

        while (($frame = $this->reader->consumeFrame($this->readBuffer)) !== null) {
            foreach ($this->awaitCallbacks as $k => $callback) {
                if ($callback($frame) === true) {
                    unset($this->awaitCallbacks[$k]);
                    continue 2; // CONTINUE WHILE LOOP
                }
            }

            if ($frame->channel === 0) {
                $this->onFrameReceived($frame);

            } else {
                if (!isset($this->channels[$frame->channel])) {
                    throw new ClientException(
                        "Received frame #{$frame->type} on closed channel #{$frame->channel}."
                    );
                }

                $this->channels[$frame->channel]->onFrameReceived($frame);
            }
        }
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
                AbstractProcess::stopAll(0,'(pid:' . posix_getpid() . ") {$throwable->getCode()}-{$throwable->getMessage()}");
            });
    }

}
