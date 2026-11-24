<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class CalendarEventMark extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['present', 'absent', 'excused'];

    protected $table = 'calendar_event_marks';

    protected $guarded = [];

    protected $casts = [
        'day' => 'date',
    ];

    public function event()
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Activity percentages for one person in one review period, keyed the same
     * way a support line looks them up.
     *
     * @return array<string, array{present: int, absent: int, excused: int, counted: int, pct: int|null}>
     */
    public static function ratesFor(int $userId, int $periodId): array
    {
        $marks = static::query()
            ->where('user_id', $userId)
            ->whereIn('status', self::STATUSES)
            ->whereHas('event', fn ($query) => $query
                ->where('rating_period_id', $periodId)
                ->whereIn('visibility', ['selected', 'everyone'])
                ->whereNotNull('category_id'))
            ->with('event.category')
            ->get();

        $grouped = [];

        foreach ($marks as $mark) {
            $category = $mark->event?->category;

            if (! $category || $category->source === 'daily') {
                continue;
            }

            $grouped[$category->rateKey()][] = $mark->status;
        }

        $rates = [];

        foreach ($grouped as $key => $statuses) {
            $rates[$key] = AttendanceMeeting::columnRate($statuses);
        }

        return $rates;
    }
}
