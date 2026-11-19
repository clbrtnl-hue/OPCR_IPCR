<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AttendanceDay extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['present', 'absent', 'leave'];

    protected $table = 'attendance_days';

    protected $guarded = [];

    protected $casts = [
        'day' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function period()
    {
        return $this->belongsTo(RatingPeriod::class, 'rating_period_id');
    }

    /**
     * The dates this period covers. Explicit period dates win. Otherwise the
     * school year is split by sequence, so each review keeps its own days.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function periodSpan(RatingPeriod $period): ?array
    {
        $period->loadMissing('schoolYear');

        if ($period->opens_at && $period->closes_at) {
            $start = $period->opens_at->copy()->startOfDay();
            $end   = $period->closes_at->copy()->startOfDay();
        } elseif ($period->schoolYear?->start_date && $period->schoolYear?->end_date) {
            $start   = $period->schoolYear->start_date->copy()->startOfDay();
            $end     = $period->schoolYear->end_date->copy()->startOfDay();
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            $mid     = $start->copy()->addSeconds(intdiv(max($seconds, 0), 2))->startOfDay();

            if ((int) $period->seq > 1) {
                $start = $mid->copy()->addDay();
            } else {
                $end = $mid;
            }
        } else {
            return null;
        }

        return $end->lt($start) ? null : [$start, $end];
    }

    /**
     * @return array<int, string>
     */
    public static function days(Carbon $start, Carbon $end): array
    {
        $days = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $days[] = $cursor->toDateString();
        }

        return $days;
    }

    /**
     * Leave stays on the sheet and stays out of the percentage.
     *
     * @return array{present: int, absent: int, leave: int, counted: int, pct: int|null}
     */
    public static function rate(int $present, int $absent, int $leave = 0): array
    {
        $counted = $present + $absent;

        return [
            'present' => $present,
            'absent'  => $absent,
            'leave'   => $leave,
            'counted' => $counted,
            'pct'     => $counted > 0 ? (int) round($present / $counted * 100) : null,
        ];
    }

    /**
     * @return array{present: int, absent: int, leave: int, counted: int, pct: int|null}
     */
    public static function rateFor(int $userId, int $periodId): array
    {
        $counts = static::query()
            ->where('user_id', $userId)
            ->where('rating_period_id', $periodId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return static::rate(
            (int) ($counts['present'] ?? 0),
            (int) ($counts['absent'] ?? 0),
            (int) ($counts['leave'] ?? 0),
        );
    }
}
