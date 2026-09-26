<?php

namespace App\Console\Commands;

use App\Services\PcrReminderService;
use Illuminate\Console\Command;

class SendPcrReminders extends Command
{
    protected $signature = 'pms:remind';

    protected $description = 'Notify owners of due and overdue commitments, of a rating period that is about to close, and QA of rated forms that still need to be closed';

    public function handle(PcrReminderService $reminders): int
    {
        $sent = $reminders->send();

        $this->info("Deadline reminders: {$sent['deadlines']}. Period reminders: {$sent['periods']}. Forms to close: {$sent['unclosed']}.");

        return self::SUCCESS;
    }
}
