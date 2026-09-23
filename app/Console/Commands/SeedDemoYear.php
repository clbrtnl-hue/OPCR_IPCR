<?php

namespace App\Console\Commands;

use Database\Seeders\DemoYear2025Seeder;
use Illuminate\Console\Command;

class SeedDemoYear extends Command
{
    protected $signature = 'pms:demo-2025 {--force : Run even outside a local environment}';

    protected $description = 'Fill the 2025 cycle with a complete demo dataset (re-runnable; the active cycle is left alone)';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed demo data in production. Pass --force if you mean it.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => DemoYear2025Seeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
