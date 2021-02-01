<?php

declare(strict_types=1);

namespace Dojiland\Amqp;

use Illuminate\Foundation\Application as LaravelApplication;
use Laravel\Lumen\Application as LumenApplication;
use Illuminate\Support\ServiceProvider;
use Dojiland\Amqp\Console\AmqpConsumerCommand;
use Dojiland\Amqp\Console\SubscribeMakeCommand;
use Dojiland\Amqp\Console\InitCommand;

/**
 * Class AmqpServiceProvider
 *
 */
class AmqpServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Bootstrap the configuration
     *
     * @return void
     */
    public function boot()
    {
        $source = realpath(__DIR__ . '/../config/amqp.php');

        if ($this->app instanceof LaravelApplication) {
            $this->publishes([$source => config_path('amqp.php')]);
        } elseif ($this->app instanceof LumenApplication) {
            $this->app->configure('amqp');
        }

        // 合并应用程序里重写的配置项
        // PS:此方法仅合并配置数组的第一级。如果您的用户部分定义了多维配置数组，则不会合并缺失的选项。
        $this->mergeConfigFrom($source, 'amqp');

        // 注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                AmqpConsumerCommand::class,     // 启动订阅监听进程
                SubscribeMakeCommand::class,    // 生成订阅消息类
                InitCommand::class,             // 初始化
            ]);
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('amqp', function ($app) {
            // Rabbitmq客户端
            return new Rabbitmq(app('log'), config('amqp'));
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['amqp'];
    }
}
