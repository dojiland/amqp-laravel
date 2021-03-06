# About amqp-laravel #

基于 [php-amqplib /
php-amqplib](https://github.com/php-amqplib/php-amqplib) 针对 Laravel && Lumen
框架封装 [Rabbitmq](https://www.rabbitmq.com) 的调用方法，方便业务开发.

## Setup

本地先安装 [composer](https://getcomposer.org), 执行以下命令加载库:
```
composer require dojiland/amqp-laravel
```

创建配置文件，执行生成 `config/amqp.php` 配置文件:
```
php artisan amqp:init
```

`.env` 添加 AMQP 配置项:
```
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
```

### Laravel
Laravel 版本 >= 5.5, 会自动 package-discover，无需操作.

less than 5.5 add this to the providers array in `config/app.php`
```
Dojiland\Amqp\AmqpServiceProvider::class
```

### Lumen
add this in `bootstrap/app.php`
```
$app->register(Dojiland\Amqp\AmqpServiceProvider::class);
```

## Usage
PS: 目前只限制使用 [Fanout Exchange](https://www.rabbitmq.com/tutorials/tutorial-three-php.html), 默认配置使用消息持久化和ACK.


### 注册消费异常回调

在某个初始化过程中，调用如下代码，实现自己的消费失败回调逻辑，
如 `app/Providers/AppServiceProvider` 的 `boot` 方法，增加 sentry 通知。
```
/**
* 含义说明
* $context = [
*    // amqp exchange
*    'exchange'  => '',
*    // amqp queue
*    'queue'     => '',
*    // amqp body (业务消息文本)
*    'payload'   => '',
* ]
*/
// amqp 消费异常上报
\Dojiland\Amqp\Events\AmqpEvent::registerConsumeFailedListener(function (\Throwable $e, array $context) {
    app('sentry')->captureException($e);
    // ...
});
```


### Publish
```
<?php
    /**
     * 单条信息发送
     * string $exchange:    RabbitMQ exchange 名称
     * array  $params:      一维参数数组
     * bool   $persistent:  消息持久化标识，默认true(持久化)
     */
    ...
    app('amqp')->publish(string $exchange, array $params, bool $persistent = true);
    ...


    /**
     * 批量消息发送
     * string $exchange:    RabbitMQ exchange 名称
     * array  $params:      二维参数数组
     * bool   $persistent:  消息持久化标识，默认true(持久化)
     */
    ...
    app('amqp')->batchPublish(string $exchange, array $params, bool $persistent = true);
    ...
```

### Subscribe
创建指定订阅者，执行命令
```
php artisan make:amqp ExampleSubscribe
```
创建 `app\MessageQueue\ExampleSubscribe.php`
文件(若MessageQueue文件夹不存在会默认生成).
```
    /**
     * 订阅的exchange.
     * @var string
     */
    protected $exchange = '';

    /**
     * 订阅的queue.
     *
     * @var string
     */
    protected $queue = '';

    /**
     * 监听消息回调处理
     *
     * @return void
     */
    public function callback(AMQPMessage $msg)
    {
        echo $msg->body;
    }
```
填写指定的 `exchange` 和 `queue`, 并在 `callback` 里添加消费逻辑.

`amqp.php` 添加订阅对象配置项:
```
    /*
    |--------------------------------------------------------------------------
    | 订阅消费类列表
    |--------------------------------------------------------------------------
    |
     */
    'subscribes'        =>  [
        \App\MessageQueue\ExampleSubscribe::class,
    ]
```

执行命令 `php artisan amqp`, 启动消费进程.
