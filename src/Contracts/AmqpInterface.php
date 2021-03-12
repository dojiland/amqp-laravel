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
     * @param array  $params
     * @param bool   $batch     true:批量发送 false:单条发送
     * @return void
     */
    public function publish(string $exchange, array $params, bool $batch = false);

    /**
     * 批量发布消息
     *
     * @param string $exchange
     * @param array  $params
     * @return void
     */
    public function batchPublish(string $exchange, array $params);
}
