<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

class PurgeTrashedNotifications extends Command
{
    protected $signature = 'pms:purge-notifications';

    protected $description = 'Permanently delete notifications that have been in Trash for 30 days';

    public function handle(): int
    {
        $deleted = Notification::purgeTrash();

        $this->info("Purged {$deleted} notification(s) from Trash.");

        return self::SUCCESS;
    }
}
