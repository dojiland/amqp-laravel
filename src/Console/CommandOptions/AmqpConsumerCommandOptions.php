<?php

declare(strict_types=1);

namespace Dojiland\Amqp\Console\CommandOptions;

class AmqpConsumerCommandOptions
{
    /**
     * The maximum amount of RAM the worker may consume.
     *
     * @var int
     */
    public $memory;

    public function __construct($memory)
    {
        $this->memory = $memory;
    }
}
