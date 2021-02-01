<?php

namespace Per3evere\Amqp\Console;

use Illuminate\Console\Command;

class InitCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'amqp:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Init AMQP and Create config file';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'AMQP Init';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $configPath = base_path(). '/config';
        if (!file_exists($configPath) && !is_dir($configPath)) {
            mkdir($configPath, 0755);
        }

        $config = $configPath . '/amqp.php';
        if (file_exists($config)) {
            $this->error('配置文件已存在!');
            return;
        }

        $data = file_get_contents(__DIR__ . '/stubs/config.stub');
        file_put_contents($config, $data);
        $this->info('配置文件创建成功');
    }
}
