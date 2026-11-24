<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AttendanceRate extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_rates';

    protected $guarded = [];

    protected $casts = [
        'pct' => 'integer',
    ];

    /**
     * The score a rater starts from. The percentage stays as evidence, and the
     * rater can replace this number before it is saved.
     */
    public static function suggestedScore(?int $pct): ?int
    {
        if ($pct === null) {
            return null;
        }

        if ($pct >= 95) {
            return 5;
        }

        if ($pct >= 90) {
            return 4;
        }

        if ($pct >= 80) {
            return 3;
        }

        if ($pct >= 70) {
            return 2;
        }

        return 1;
    }

    /**
     * Store the daily percentage and the activities percentage for one person.
     * Leave and excused stay in the record and stay out of the percentage.
     */
    /**
     * Daily attendance plus every activity category, including calendar events.
     *
     * @return array<string, array<string, int|null>>
     */
    public static function bundle(int $userId, int $periodId): array
    {
        $rates = array_merge(
            ['daily' => AttendanceDay::rateFor($userId, $periodId)],
            AttendanceMeeting::ratesFor($userId, $periodId),
        );

        foreach (CalendarEventMark::ratesFor($userId, $periodId) as $key => $rate) {
            if ($key === 'daily') {
                continue;
            }

            $rates[$key] = isset($rates[$key])
                ? AttendanceMeeting::combineRates($rates[$key], $rate)
                : $rate;
        }

        return $rates;
    }

    public static function remember(int $userId, int $periodId): void
    {
        $rates = static::bundle($userId, $periodId);
        $daily = $rates['daily'];

        static::store($userId, $periodId, 'daily', [
            'present' => $daily['present'],
            'absent'  => $daily['absent'],
            'excused' => $daily['leave'] ?? 0,
            'counted' => $daily['counted'],
            'pct'     => $daily['pct'],
        ]);

        $present = 0;
        $absent = 0;
        $excused = 0;

        foreach ($rates as $key => $rate) {
            if ($key === 'daily') {
                continue;
            }

            $present += (int) ($rate['present'] ?? 0);
            $absent += (int) ($rate['absent'] ?? 0);
            $excused += (int) ($rate['excused'] ?? 0);
            static::store($userId, $periodId, (string) $key, $rate);
        }

        $counted = $present + $absent;

        static::store($userId, $periodId, 'activities', [
            'present' => $present,
            'absent'  => $absent,
            'excused' => $excused,
            'counted' => $counted,
            'pct'     => $counted > 0 ? (int) round($present / $counted * 100) : null,
        ]);
    }

    /**
     * @param  array{present: int, absent: int, excused?: int, counted: int, pct: int|null}  $rate
     */
    public static function store(int $userId, int $periodId, string $scope, array $rate): void
    {
        static::updateOrCreate(
            [
                'user_id'          => $userId,
                'rating_period_id' => $periodId,
                'scope'            => $scope,
            ],
            [
                'present' => $rate['present'],
                'absent'  => $rate['absent'],
                'excused' => $rate['excused'] ?? 0,
                'counted' => $rate['counted'],
                'pct'     => $rate['pct'],
            ],
        );
    }

    /**
     * @return array<int, array{present: int, absent: int, excused: int, counted: int, pct: int|null}>
     */
    public static function forPeriod(int $periodId, string $scope): array
    {
        return static::query()
            ->where('rating_period_id', $periodId)
            ->where('scope', $scope)
            ->get()
            ->mapWithKeys(fn (self $rate) => [
                $rate->user_id => [
                    'present' => (int) $rate->present,
                    'absent'  => (int) $rate->absent,
                    'excused' => (int) $rate->excused,
                    'counted' => (int) $rate->counted,
                    'pct'     => $rate->pct,
                ],
            ])
            ->all();
    }

    /**
     * One percentage per activity category, for the whole rating period.
     *
     * @return array<int, array<string, array{present: int, absent: int, excused: int, counted: int, pct: int|null}>>
     */
    public static function categoriesForPeriod(int $periodId): array
    {
        $rates = [];

        $rows = static::query()
            ->where('rating_period_id', $periodId)
            ->where(function ($query) {
                $query->whereIn('scope', array_keys(AttendanceMeeting::CATEGORIES))
                    ->orWhere('scope', 'like', 'c%');
            })
            ->get();

        foreach ($rows as $rate) {
            $rates[$rate->user_id][$rate->scope] = [
                'present' => (int) $rate->present,
                'absent'  => (int) $rate->absent,
                'excused' => (int) $rate->excused,
                'counted' => (int) $rate->counted,
                'pct'     => $rate->pct,
            ];
        }

        return $rates;
    }
}
