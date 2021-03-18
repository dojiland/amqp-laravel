<?php

declare(strict_types=1);

namespace Dojiland\Amqp;

use Dojiland\Amqp\Events\AmqpEvent;
use Dojiland\Amqp\Console\CommandOptions\AmqpConsumerCommandOptions;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use Psr\Log\LoggerInterface;

/**
 * Amqp功能实例类
 * exchange定义参考：https://www.rabbitmq.com/amqp-0-9-1-reference.html#exchange.declare
 * queue定义参考：https://www.rabbitmq.com/amqp-0-9-1-reference.html#queue.declare
 */
class Rabbitmq extends AbstractAmqp
{
    /**
     * @var AmqpConnection
     */
    protected $connection = null;

    /**
     * @var AMQPChannel
     */
    protected $channel = null;

    /**
     * rabbitmq系统保留名称前缀
     *
     * @var string
     */
    protected $reservedSuffixName = 'amq.';

    /**
     * 初始化连接，默认AMQPStreamConnection
     *
     * @param LoggerInterface $log
     * @param array $config 配置项
     */
    public function __construct(LoggerInterface $log, array $config)
    {
        // 启动调试模式
        if (array_key_exists('amqp_debug', $config) && $config['amqp_debug'] && !defined('AMQP_DEBUG')) {
            define('AMQP_DEBUG', true);
        }

        parent::__construct($log, $config);
    }

    /**
     * 连接mq
     *
     * @return void
     */
    protected function connect()
    {
        $config = $this->config;
        $this->connection = AMQPStreamConnection::create_connection([
            [
                'host'     => $config['host'],
                'port'     => $config['port'],
                'user'     => $config['user'],
                'password' => $config['password'],
                'vhost'    => $config['vhost']
            ]
        ], ['heartbeat' => 60]);
    }

    /**
     * 获取channel实例
     *
     * @return AMQPChannel
     */
    protected function getChannel()
    {
        // 检查是否已连接
        if (is_null($this->connection)) {
            $this->connect();
        }
        // 判断channel是否已初始化或者是否已被关闭
        if (is_null($this->channel) || !$this->channel->is_open()) {
            $this->channel = $this->connection->channel();
        }
        return $this->channel;
    }

    /**
     * 释放连接资源
     *
     * @return void
     */
    public function close()
    {
        try {
            if (!is_null($this->channel)) {
                $this->channel->close();
            }
        } catch (\Throwable $e) { }
        try {
            if (!is_null($this->connection)) {
                $this->connection->close();
            }
        } catch (\Throwable $e) { }
        $this->channel = null;
        $this->connection = null;
    }

    /**
     * 检查名称前缀
     * @param string $name exchange或queue名称
     *
     * @return bool
     */
    private function checkSuffixName(string $name) : bool
    {
        if (stripos($name, $this->reservedSuffixName) === 0) {
            return false;
        }
        return true;
    }

    /**
     * 发布消息
     *
     * @param string $exchange
     * @param array  $params
     * @param bool   $persistent true:消息持久化 false:消息非持久化
     * @param bool   $batch      true:批量发送 false:单条发送
     * @return void
     * @throw InvalidArgumentException
     */
    public function publish(string $exchange, array $params, bool $persistent = true, bool $batch = false)
    {
        // exchange名称前缀检测
        if (!$this->checkSuffixName($exchange)) {
            throw new \InvalidArgumentException('exchange前缀不能为`'.$this->reservedSuffixName.'`');
        }

        // 首次初始化连接
        $this->init();

        // 失败重试次数
        $retry = 0;
        while (true) {
            $retry++;
            try {
                // 获取channel
                $channel = $this->getChannel();

                // 改动配置项(2，3)：
                // durable ==> true         设置exchange持久化
                // auto_delete ==> false    channel关闭后，exchange不会被自动删除
                $channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, true, false);

                $properties = [
                    'content_type' => 'application/json',
                ];
                // 定义消息持久化存储
                if ($persistent) {
                    $properties['delivery_mode'] = AMQPMessage::DELIVERY_MODE_PERSISTENT;
                }
                if ($batch) {
                    // 无批量发送数据
                    if (count($params) == 0) {
                        return;
                    }
                    foreach ($params as $info) {
                        $message = new AMQPMessage(json_encode($info), $properties);
                        $channel->batch_basic_publish($message, $exchange);
                    }
                    // 批量数据发送
                    $channel->publish_batch();
                } else {
                    // 单条数据发送
                    $message = new AMQPMessage(json_encode($params), $properties);
                    $channel->basic_publish($message, $exchange);
                }

                // 发送完成
                return;
            } catch (AMQPConnectionClosedException $e) {
                // 处理心跳包超时错误
                if ($e->getMessage() == 'Missed server heartbeat') {
                    $this->log->error('amqp heartbeat missed excetion, reconnect', [
                        'msg'   => $e->getMessage(),
                        'retry' => $retry,
                    ]);
                    // 重连
                    $this->reconnect();

                    if ($retry < 3) {
                        continue;
                    }
                }
                throw $e;
            }
        }
    }

    /**
     * 批量发布消息
     *
     * @param string $exchange
     * @param array  $params
     * @param bool   $persistent true:消息持久化 false:消息非持久化
     * @return void
     * @throw InvalidArgumentException
     */
    public function batchPublish(string $exchange, array $params, bool $persistent = true)
    {
        $this->publish($exchange, $params, $persistent, true);
    }

    /**
     * 启动订阅监听 loop
     *
     * @return void
     */
    public function run(AmqpConsumerCommandOptions $options)
    {
        $log = $this->log;
        $subscribes = $this->config['subscribes'] ?? [];
        if (empty($subscribes) || !is_array($subscribes)) {
            $this->log->error('unset subscribes，quit...');
            return;
        }
        $log->info('RabbitMQ Consumer start runing...', $subscribes);

        $retry = 0;
        $maxRetry = min($this->config['reconnect_retry'], self::RECONNECT_RETRY_MAX);
        while ($retry < $maxRetry) {
            try {
                $log->info('RabbitMQ start connecting...', [
                    'retry'     => $retry,
                    'maxRetry'  => $maxRetry,
                ]);
                // 首次或任意次按重连逻辑处理
                $this->reconnect();
                $log->info('RabbitMQ connect success');

                $channel = $this->getChannel();
                // 设置PREFETCH为1
                $channel->basic_qos(null, 1, null);

                // 订阅具体对象
                foreach ($subscribes as $subscribe) {
                    $this->subscribe($channel, $subscribe);
                }

                // loop
                while ($channel->is_consuming()) {
                    $channel->wait();

                    // 业务必须执行成功，再retry置0
                    // 否则如果在wait阶段抛出异常并被catch，会导致无限重试
                    if ($retry > 0) {
                        $retry = 0;
                    }

                    // 检查是否接收到退出信号
                    if ($this->shouldQuit) {
                        $this->log->info('quit!');
                        exit;
                    }
                    // 检查内容占用情况
                    if ($this->checkMemoryExceeded($options->memory)) {
                        $this->log->info('memory exceeded!');
                        exit;
                    }
                }
            } catch(AMQPRuntimeException $e) {
                $log->error('RabbitMQ Connection AMQPRuntimeException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch (AMQPIOException $e) {
                $log->error('RabbitMQ Connection AMQPIOException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch(\RuntimeException $e) {
                $log->error('RabbitMQ Connection RuntimeException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch(\ErrorException $e) {
                $log->error('RabbitMQ Connection ErrorException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch (\Throwable $e) {
                // 其它可能的未知异常，先不进行连接重试
                $log->error('RabbitMQ Connection Throwable:'.$e->getMessage());
                throw $e;
            }

            // 按指数休眠
            sleep(pow(2, $retry++));
        }
        $log->info('RabbitMQ connect failed and quit...');
    }

    /**
     * 添加订阅监听对象
     *
     * @param AMQPChannel channel
     * @param string $subscribe 订阅类名
     * @return void
     */
    private function subscribe(AMQPChannel $channel, string $subscribe)
    {
        $sub = new $subscribe();
        $exchange = $sub->getExchange();
        $queue = $sub->getQueue();
        if (empty($exchange) || !$this->checkSuffixName($exchange)) {
            throw new \InvalidArgumentException('exchange名称定义异常');
        }
        if (empty($queue) || !$this->checkSuffixName($queue)) {
            throw new \InvalidArgumentException('queue名称定义异常');
        }

        $log = $this->log;
        // 改动的配置项(2，4)：
        // durable ==> true          设置queue持久化
        // auto_delete ==> false     channel关闭后，queue不会被自动删除
        $channel->queue_declare($queue, false, true, false, false);
        // 改动的配置项(2，3)：
        // durable ==> true          设置exchange持久化
        // auto_delete ==> false     channel关闭后，exchange不会被自动删除
        $channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, true, false);

        $channel->queue_bind($queue, $exchange);

        $channel->basic_consume($queue, '', false, false, false, false, function (
            AMQPMessage $message
        ) use ($sub, $log) {
            $context = [
                'exchange'  => $sub->getExchange(),
                'queue'     => $sub->getQueue(),
                'payload'   => $message->body,
            ];
            $log->debug('receive message queue', $context);

            try {
                // 执行业务回调
                $sub->callback($message);
            } catch (\Throwable $e) {
                $log->error('message queue执行异常', array_merge([
                    'errorMsg'  => $e->getMessage(),
                ], $context));

                // 执行异常监听处理
                AmqpEvent::emitConsumeFailedEvent($e, $context);
            } finally {
                // 忽略callback执行结果，统一ack
                $message->ack();
            }
        });
    }
}
