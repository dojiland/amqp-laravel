<?php

declare(strict_types=1);

namespace Dojiland\Amqp\Console;

use Dojiland\Amqp\Console\CommandOptions\AmqpConsumerCommandOptions;
use Illuminate\Console\Command;

class AmqpConsumerCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'amqp
                            {--memory=128 : The memory limit in megabytes}';

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
        $options = new AmqpConsumerCommandOptions($this->option('memory'));
        app('amqp')->run($options);
    }
}
