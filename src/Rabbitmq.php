<?php

declare(strict_types=1);

namespace Dojiland\Amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPIOException;
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
     * publish调用exchange已发布清单
     *
     * @var array
     */
    private $publishExchangeDeclaredList = [];

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
        $this->connection = new AMQPStreamConnection(
            $config['host'], $config['port'], $config['user'], $config['password'], $config['vhost']
        );
        $this->channel = $this->connection->channel();
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
            if (!is_null($this->connection)) {
                $this->connection->close();
            }
            unset($this->publishExchangeDeclaredList);
        } catch (\Throwable $e) { }
        $this->channel = null;
        $this->connection = null;
        $this->publishExchangeDeclaredList = [];
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
     * @param array $params
     * @return void
     * @throw InvalidArgumentException
     */
    public function publish(string $exchange, array $params)
    {
        // exchange名称前缀检测
        if (!$this->checkSuffixName($exchange)) {
            throw new \InvalidArgumentException('exchange前缀不能为`'.$this->suffixName.'`');
        }

        // 首次初始化连接
        $this->init();

        $channel = $this->channel;
        // 每次declare exchange后全局存储，不重复调用
        if (!in_array($exchange, $this->publishExchangeDeclaredList)) {
            // 改动配置项(2，3)：
            // durable ==> true         设置exchange持久化
            // auto_delete ==> false    channel关闭后，exchange不会被自动删除
            $channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, true, false);
            $this->publishExchangeDeclaredList[] = $exchange;
        }
        $properties = [
            'content_type' => 'text/plain',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];
        $message = new AMQPMessage(json_encode($params), $properties);
        $channel->basic_publish($message, $exchange);
    }

    /**
     * 启动订阅监听 loop
     *
     * @return void
     */
    public function run()
    {
        $log = $this->log;
        $subscribes = $this->config['subscribes'] ?? [];
        if (empty($subscribes) || !is_array($subscribes)) {
            $this->log->debug('unset subscribes，quit...');
            return;
        }
        $log->debug('start runing...', $subscribes);

        $retry = 0;
        $maxRetry = min($this->config['reconnect_retry'], self::RECONNECT_RETRY_MAX);
        while ($retry < $maxRetry) {
            try {
                $log->info('start connecting...', [
                    'retry'     => $retry,
                    'maxRetry'  => $maxRetry,
                ]);
                // 首次或任意次按重连逻辑处理
                $this->reconnect();
                $log->info('connect success');

                $channel = $this->channel;
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
                }
            } catch(AMQPRuntimeException $e) {
                $log->error('Connection AMQPRuntimeException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch (AMQPIOException $e) {
                $log->error('Connection AMQPIOException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch(\RuntimeException $e) {
                $log->error('Connection RuntimeException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch(\ErrorException $e) {
                $log->error('Connection ErrorException:'.$e->getMessage(), [
                    // 'trace' => $e->getTraceAsString(),
                    'retry' => $retry,
                ]);
            } catch (\Throwable $e) {
                // 其它可能的未知异常，先不进行连接重试
                $log->error('Connection Throwable:'.$e->getMessage());
                throw $e;
            }

            // 按指数休眠
            sleep(pow(2, $retry++));
        }
        $log->info('connect failed and quit...');
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
        if (!$this->checkSuffixName($exchange)) {
            throw new \InvalidArgumentException('exchange前缀不能为`'.$this->suffixName.'`');
        }
        if (!$this->checkSuffixName($queue)) {
            throw new \InvalidArgumentException('queue前缀不能为`'.$this->suffixName.'`');
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
            }

            // 忽略callback执行结果，统一ack
            $message->ack();
        });
    }
}
