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
     * 程序退出标识
     *
     * @var bool
     */
    protected $shouldQuit = false;

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

        // cli 增加 signal 监听
        if (php_sapi_name() == 'cli' && $this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        register_shutdown_function([$this, 'close']);
    }

    /**
     * 覆盖指定配置项
     *
     * @param array $config
     * @return void
     */
    public function setConfig($key, $value = null)
    {
        if (is_array($key)) {
            $this->config = array_merge($this->config, $key);
        } else {
            $this->config[$key] = $value;
        }
    }

    /**
     * 获取配置项
     *
     * @param string|null $key
     * @param mix $value
     * @return mixed
     */
    public function getConfig(?string $key = null, $default = null)
    {
        if (empty($key)) {
            return $this->config;
        } else {
            return $this->config[$key] ?? $default;
        }
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
     * 检查是否支持信号
     *
     * @return bool
     */
    private function supportsAsyncSignals()
    {
        return \function_exists('pcntl_signal') && \function_exists('pcntl_async_signals');
    }

    /**
     * 增加信号监听
     *
     * @return void
     */
    private function listenForSignals()
    {
        pcntl_async_signals(true);

        pcntl_signal(\SIGTERM, function () {
            $this->log->info('receive SIGTERM signal, process will quit soon.');
            $this->shouldQuit = true;
        });
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param  int   $memoryLimit
     * @return bool
     */
    public function checkMemoryExceeded($memoryLimit)
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }
}
