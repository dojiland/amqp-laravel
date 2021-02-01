<?php

declare(strict_types=1);

namespace Dojiland\Amqp\Subscribe;

use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractSubscribe
{
    /**
     * 订阅的exchange.
     * @var string
     */
    protected $exchange;

    /**
     * 订阅的queue.
     * @var string
     */
    protected $queue;

    /**
     * 监听消息回调处理
     * @param AMQPMessage $msg
     */
    abstract function callback(AMQPMessage $msg);

    /**
     * 获取exchange
     * @return string
     * */
    public function getExchange() : string
    {
        return $this->exchange;
    }

    /**
     * 获取queue
     * @return string
     * */
    public function getQueue() : string
    {
        return $this->queue;
    }
}
