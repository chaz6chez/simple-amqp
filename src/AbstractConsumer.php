<?php
declare(strict_types=1);

namespace SimpleAmqp;

use Kernel\AbstractProcess;

abstract class AbstractConsumer extends AbstractProcess {
    protected static $_check = false;

    protected static $_debug = false;

    /** @var Builder */
    protected $_builder;

    public static function check(?bool $check = null) : bool
    {
        if($check !== null){
            self::$_check = $check;
        }
        return self::$_check;
    }

    public static function debug(bool $debug = true) {
        self::$_debug = $debug;
    }

    public function __invoke(): AbstractProcess
    {
        $this->_init();
        if(self::check() === false){
            self::check(true);
            $this->_dump('> Detecting rabbitmq connection ...');
            if(!$this->_builder()->consumer()->checker()){
                $this->_dump('? ' . get_called_class());
                $this->_dump('? ' . $this->_builder->consumer()->getError()->getMessage());
                $this->_dump('> Rabbitmq connection failed ...');
                exit();
            }
            $this->_dump('> Rabbitmq successfully connected ...');
        }
        return parent::__invoke();
    }

    public function onStart(...$param): void
    {
        $this->_init();
        $this->_builder()->consume();
    }

    public function onReload(...$param): void
    {
        $this->_builder()->consumer()->reconnect();
    }

    public function onStop(...$param): void
    {
        $this->_builder()->consumer()->close();
        $this->_builder = null;
    }

    /**
     * @param $data
     */
    protected function _dump($data){
        if (self::$_debug) {
            self::safeEcho(json_encode($data,JSON_UNESCAPED_UNICODE));
        }else{
            self::log(json_encode($data,JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @return Builder
     */
    protected function _builder() : Builder
    {
        return $this->_builder;
    }

    /**
     * init Builder、Logger and so on
     */
    abstract protected function _init() : void;

}