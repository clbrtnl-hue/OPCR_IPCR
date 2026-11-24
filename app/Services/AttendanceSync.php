<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceMeetingMark;
use App\Models\CalendarEventMark;

/**
 * An activity mark can fill the everyday cell for that date.
 * Present wins, then absent, then excused, which is stored as leave.
 * A day already marked leave is left alone.
 */
class AttendanceSync
{
    public static function dayFromActivities(int $actorId, int $personId, int $periodId, string $day): void
    {
        $existing = AttendanceDay::query()
            ->where('user_id', $personId)
            ->whereDate('day', $day)
            ->first();

        if ($existing && $existing->status === 'leave') {
            return;
        }

        $statuses = AttendanceMeetingMark::query()
            ->where('user_id', $personId)
            ->whereHas('meeting', fn ($query) => $query
                ->where('rating_period_id', $periodId)
                ->whereDate('held_on', $day))
            ->pluck('status');

        $eventStatuses = CalendarEventMark::query()
            ->where('user_id', $personId)
            ->whereDate('day', $day)
            ->whereHas('event', fn ($query) => $query
                ->where('rating_period_id', $periodId)
                ->whereIn('visibility', ['selected', 'everyone'])
                ->whereNotNull('category_id'))
            ->pluck('status');

        $statuses = $statuses->concat($eventStatuses);
        $status = null;

        if ($statuses->contains('present')) {
            $status = 'present';
        } elseif ($statuses->contains('absent')) {
            $status = 'absent';
        } elseif ($statuses->contains('excused')) {
            $status = 'leave';
        }

        static::writeDay($personId, $day, $status, $periodId, $actorId);
    }

    public static function writeDay(int $personId, string $day, ?string $status, int $periodId, int $actorId): void
    {
        $existing = AttendanceDay::query()
            ->where('user_id', $personId)
            ->whereDate('day', $day)
            ->first();

        if ($status === null) {
            $existing?->delete();

            return;
        }

        if ($existing) {
            $existing->update([
                'rating_period_id' => $periodId,
                'marked_by'        => $actorId,
                'status'           => $status,
            ]);

            return;
        }

        AttendanceDay::create([
            'user_id'          => $personId,
            'day'              => $day,
            'rating_period_id' => $periodId,
            'marked_by'        => $actorId,
            'status'           => $status,
        ]);
    }
}
