<?php

namespace Dojiland\Amqp\Contracts;

use Dojiland\Amqp\Console\CommandOptions\AmqpConsumerCommandOptions;

/**
 * Interface AmqpInterface
 */
interface AmqpInterface
{
    /**
     * 启动订阅监听 loop
     */
    public function run(AmqpConsumerCommandOptions $options);

    /**
     * 发布消息
     *
     * @param string $exchange
     * @param array $params
     * @return void
     */
    public function publish(string $exchange, array $params);
}
