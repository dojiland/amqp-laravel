<?php

declare(strict_types=1);

namespace Per3evere\Amqp\Console;

use Illuminate\Console\Command;

class AmqpConsumerCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'amqp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '启动 amqp-consumer 监听服务.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        app('amqp')->run();
    }
}
