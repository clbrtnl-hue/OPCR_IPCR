<?php

namespace App\Console\Commands;

use App\Services\CalendarReminderService;
use App\Services\PcrReminderService;
use Illuminate\Console\Command;

class SendPcrReminders extends Command
{
    protected $signature = 'pms:remind';

    protected $description = 'Notify owners of due and overdue commitments, of a rating period that is about to close, and of calendar events coming up';

    public function handle(PcrReminderService $reminders, CalendarReminderService $calendar): int
    {
        $sent = $reminders->send();
        $events = $calendar->send();

        $this->info("Deadline reminders: {$sent['deadlines']}. Period reminders: {$sent['periods']}. Calendar reminders: {$events}.");

        return self::SUCCESS;
    }
}
