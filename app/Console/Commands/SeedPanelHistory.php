<?php

namespace App\Console\Commands;

use Database\Seeders\PanelHistorySeeder;
use Illuminate\Console\Command;

class SeedPanelHistory extends Command
{
    protected $signature = 'pms:history {--force : Run even outside a local environment}';

    protected $description = 'Load at least two months of 2026 transactions so every module can generate reports';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed demo data in production. Pass --force if you mean it.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => PanelHistorySeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
