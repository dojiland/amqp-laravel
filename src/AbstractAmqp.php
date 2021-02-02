<?php

declare(strict_types=1);

namespace Dojiland\Amqp;

use Dojiland\Amqp\Contracts\AmqpInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractAmqp implements AmqpInterface
{
    /**
     * 重试最大尝试次数
     *
     * @var int
     */
    const RECONNECT_RETRY_MAX = 10;

    /**
     * 日志工具类
     *
     * @var LoggerInterface
     */
    protected $log;

    /**
     * AMQP配置项
     *
     * @var array
     */
    protected $config;

    /**
     * 初始化标记
     *
     * @var bool
     */
    protected $initFlag = false;

    /**
     * 初始化连接，默认AMQPStreamConnection
     *
     * @param LoggerInterface $log
     * @param array $config 配置项
     */
    public function __construct(LoggerInterface $log, array $config)
    {
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * 覆盖指定配置项
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * 获取配置项
     *
     * @return array
     */
    public function getConfig() : array
    {
        return $this->config;
    }

    /**
     * 初始化连接
     *
     * @return void
     */
    protected function init()
    {
        if (!$this->initFlag) {
            $this->connect();
            $this->initFlag = true;
        }
    }

    /**
     * 连接mq
     *
     * @return void
     */
    abstract protected function connect();

    /**
     * 重连mq
     *
     * @return void
     */
    public function reconnect()
    {
        // 首次不执行close操作并设置已初始化标识
        if (!$this->initFlag) {
            $this->initFlag = true;
        } else {
            $this->close();
        }
        $this->connect();
    }

    /**
     * 任务退出自动触发释放资源
     */
    public function __destruct()
    {
        $this->close();
    }
}
