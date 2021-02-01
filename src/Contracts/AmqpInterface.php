<?php

namespace Per3evere\Amqp\Contracts;

/**
 * Interface AmqpInterface
 */
interface AmqpInterface
{
    /**
     * 启动订阅监听 loop
     * */
    public function run();

    /**
     * 发布消息
     * @param string $exchange
     * @param array $params
     * */
    public function publish(string $exchange, array $params);
}
