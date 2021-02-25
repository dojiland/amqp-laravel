<?php
declare(strict_types=1);

namespace Dojiland\Amqp\Events;

use Evenement\EventEmitter;

class AmqpEvent
{
    /**
     * 消费失败EventName
     */
    const CONSUME_FAILED = 'consume_failed';

    /**
     * @var EventEmitter
     */
    private static $emitter;

    private static function getEmitter()
    {
        if (!self::$emitter instanceof EventEmitter) {
            self::$emitter = new EventEmitter();
        }
        return self::$emitter;
    }

    /**
     * 注册执行失败监听
     *
     * @return void
     */
    public static function registerConsumeFailedListener(\Closure $closure)
    {
        $reflect = new \ReflectionFunction($closure);
        $params = $reflect->getParameters();
        if (count($params) != 2) {
            throw new \InvalidArgumentException('registerFailedListener注册监听参数异常,数量应为2');
        }
        if ($params[0]->getType() != 'Throwable') {
            throw new \InvalidArgumentException('第一个参数类型应为`Throwable`');
        }
        if ($params[1]->getType() != 'array') {
            throw new \InvalidArgumentException('第二个参数类型应为`array`');
        }

        self::getEmitter()->on(self::CONSUME_FAILED, $closure);
    }

    /**
     * 触发失败监听事件
     *
     * @param Throwable $e 异常
     * @param array $context 上下文
     * @return void
     */
    public static function emitConsumeFailedEvent(\Throwable $e, array $context)
    {
        self::getEmitter()->emit(self::CONSUME_FAILED, [$e, $context]);
    }
}
