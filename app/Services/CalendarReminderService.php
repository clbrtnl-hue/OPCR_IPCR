<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\User;

/**
 * Morning reminders for events already on the calendar. A timed event is
 * mentioned the day before and again on the day. An all-day event is
 * mentioned on the day itself. The person who created an Only me event is
 * included. A moved date gets a new reminder; a deleted event does not.
 */
class CalendarReminderService
{
    /** @var array<int, array<int, int>> */
    private array $leadersByUnit = [];

    public function send(): int
    {
        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        $events = CalendarEvent::query()
            ->with(['owner:id,role,status', 'viewers:id,status'])
            ->where('starts_at', '>=', $today)
            ->where('starts_at', '<', $tomorrow->copy()->addDay())
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $startDay = $event->starts_at->copy()->startOfDay();
            $todayEvent = $startDay->equalTo($today);
            $tomorrowEvent = $startDay->equalTo($tomorrow);

            if ($tomorrowEvent && $event->all_day) {
                continue;
            }

            if (! $todayEvent && ! $tomorrowEvent) {
                continue;
            }

            if ($todayEvent && $event->ends_at->lt(now())) {
                continue;
            }

            $when = $todayEvent ? 'today' : 'tomorrow';
            $on = $startDay->toDateString();
            $title = ($todayEvent ? 'Today: ' : 'Tomorrow: ') . $event->title;
            $body = $this->whenLabel($event);
            $link = "/attendance?tab=calendar&event={$event->id}&on={$on}&when={$when}";

            foreach ($this->recipientIds($event) as $userId) {
                $sent += (int) Notification::remind($userId, 'calendar', $title, $body, null, $link);
            }
        }

        return $sent;
    }

    /**
     * Active people who can see this event, including the person who made it.
     *
     * @return array<int, int>
     */
    private function recipientIds(CalendarEvent $event): array
    {
        $ids = [];
        $owner = $event->owner;

        if ($owner && $owner->status === 'active') {
            $ids[] = (int) $owner->id;
        }

        if ($event->visibility === 'selected') {
            foreach ($event->viewers as $viewer) {
                if ($viewer->status === 'active') {
                    $ids[] = (int) $viewer->id;
                }
            }
        } elseif ($event->visibility === 'role' && $event->audience_role) {
            $ids = array_merge($ids, User::query()
                ->where('status', 'active')
                ->where('role', $event->audience_role)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all());
        } elseif ($event->visibility === 'everyone') {
            $ids = array_merge($ids, $this->everyoneIds($event));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, int>
     */
    private function everyoneIds(CalendarEvent $event): array
    {
        if (in_array($event->owner?->role, CalendarEvent::COLLEGE_WIDE, true)) {
            return User::query()
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $creatorId = (int) $event->created_by;
        $ids = [];

        User::query()
            ->where('status', 'active')
            ->whereNotNull('org_unit_id')
            ->get(['id', 'org_unit_id'])
            ->each(function (User $user) use ($creatorId, &$ids) {
                $leaders = $this->leadersOf((int) $user->org_unit_id);

                if (in_array($creatorId, $leaders, true)) {
                    $ids[] = (int) $user->id;
                }
            });

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function leadersOf(int $unitId): array
    {
        if (! array_key_exists($unitId, $this->leadersByUnit)) {
            $this->leadersByUnit[$unitId] = OrgUnit::leaderIdsForMember($unitId);
        }

        return $this->leadersByUnit[$unitId];
    }

    private function whenLabel(CalendarEvent $event): string
    {
        if ($event->all_day) {
            return 'All day';
        }

        $start = $event->starts_at->timezone('Asia/Manila');
        $end = $event->ends_at->timezone('Asia/Manila');
        $range = $start->format('g:i A') . ' – ' . $end->format('g:i A');

        if ($start->isSameDay($end)) {
            return $range;
        }

        return $start->format('M j, g:i A') . ' – ' . $end->format('M j, g:i A');
    }
}
