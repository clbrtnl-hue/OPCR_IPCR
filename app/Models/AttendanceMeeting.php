<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AttendanceMeeting extends Model
{
    use BelongsToOrganization;

    public const CATEGORIES = [
        'meeting'     => 'Attendance to meetings',
        'school'      => 'Attendance to school activities',
        'lgu'         => 'Attendance to LGU activities',
        'convocation' => 'Monday convocation',
        'training'    => 'Relevant trainings',
    ];

    /**
     * Checked in this order. DTR is the daily record. Convocation wins over LGU,
     * training wins over meeting when both words appear, and unmatched support
     * titles stay null (no suggested attendance score).
     */
    private const TITLE_RULES = [
        'daily'       => ['dtr', 'daily attendance', 'daily time record', 'daily time', 'time record', 'timekeeping', 'time keeping', 'bundy'],
        'convocation' => ['convocation', 'flag rais', 'flag ceremon', 'flag ceremony', 'flag retreat'],
        'training'    => ['training', 'seminar', 'symposia', 'symposium', 'workshop', 'webinar', 'capability building', 'capability-building', 'in-service', 'retooling'],
        'lgu'         => ['lgu', 'outreach', 'barangay', 'municipal activit', 'local government'],
        'school'      => ['school activit', 'departmental', 'institutional', 'campus activit', 'college activit', 'foundation day'],
        'meeting'     => ['meeting', 'mancom', 'execom'],
    ];

    protected $table = 'attendance_meetings';

    protected $guarded = [];

    protected $casts = [
        'held_on' => 'date',
    ];

    public function marks()
    {
        return $this->hasMany(AttendanceMeetingMark::class, 'meeting_id');
    }

    public function refreshSavedRate(): void
    {
        $rate = static::columnRate($this->marks()->pluck('status')->all());

        $this->forceFill([
            'present_count' => $rate['present'],
            'absent_count'  => $rate['absent'],
            'excused_count' => $rate['excused'],
            'counted'       => $rate['counted'],
            'pct'           => $rate['pct'],
        ])->save();
    }

    /**
     * @return array{present: int, absent: int, excused: int, counted: int, pct: int|null}
     */
    public function savedRate(): array
    {
        return [
            'present' => (int) $this->present_count,
            'absent'  => (int) $this->absent_count,
            'excused' => (int) $this->excused_count,
            'counted' => (int) $this->counted,
            'pct'     => $this->pct === null ? null : (int) $this->pct,
        ];
    }

    public function unit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    /**
     * Which attendance line a support-function title belongs to.
     * Submission of DTR uses the everyday record, the basis for the 95% target.
     * A core line is not classified here.
     */
    public static function categoryForTitle(?string $title): ?string
    {
        $title = mb_strtolower(trim((string) $title));

        if ($title === '') {
            return null;
        }

        foreach (self::TITLE_RULES as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($title, $needle)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function categoryList(): array
    {
        $list = [];

        foreach (self::CATEGORIES as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label];
        }

        return $list;
    }

    /**
     * The rate for one activity column. Excused is counted in the display and
     * stays out of the percentage, the same way leave stays out of the daily rate.
     *
     * @param  array<int, string>  $statuses
     * @return array{present: int, absent: int, excused: int, counted: int, pct: int|null}
     */
    public static function columnRate(array $statuses): array
    {
        $present = count(array_filter($statuses, fn ($status) => $status === 'present'));
        $absent  = count(array_filter($statuses, fn ($status) => $status === 'absent'));
        $excused = count(array_filter($statuses, fn ($status) => $status === 'excused'));
        $counted = $present + $absent;

        return [
            'present' => $present,
            'absent'  => $absent,
            'excused' => $excused,
            'counted' => $counted,
            'pct'     => $counted > 0 ? (int) round($present / $counted * 100) : null,
        ];
    }

    /**
     * @param  array{present?: int, absent?: int, excused?: int}  $left
     * @param  array{present?: int, absent?: int, excused?: int}  $right
     * @return array{present: int, absent: int, excused: int, counted: int, pct: int|null}
     */
    public static function combineRates(array $left, array $right): array
    {
        $present = (int) ($left['present'] ?? 0) + (int) ($right['present'] ?? 0);
        $absent  = (int) ($left['absent'] ?? 0) + (int) ($right['absent'] ?? 0);
        $excused = (int) ($left['excused'] ?? 0) + (int) ($right['excused'] ?? 0);
        $counted = $present + $absent;

        return [
            'present' => $present,
            'absent'  => $absent,
            'excused' => $excused,
            'counted' => $counted,
            'pct'     => $counted > 0 ? (int) round($present / $counted * 100) : null,
        ];
    }

    /**
     * Excused stays on the meeting and stays out of the percentage.
     *
     * @return array<string, array{present: int, absent: int, excused: int, counted: int, pct: int|null}>
     */
    public static function ratesFor(int $userId, int $periodId): array
    {
        $marks = AttendanceMeetingMark::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['present', 'absent', 'excused'])
            ->whereHas('meeting', fn ($query) => $query->where('rating_period_id', $periodId))
            ->with('meeting:id,category')
            ->get();

        $rates = [];

        foreach (array_keys(self::CATEGORIES) as $category) {
            $rows = $marks->filter(fn (AttendanceMeetingMark $mark) => $mark->meeting?->category === $category);
            $present = $rows->where('status', 'present')->count();
            $absent  = $rows->where('status', 'absent')->count();
            $excused = $rows->where('status', 'excused')->count();
            $counted = $present + $absent;

            $rates[$category] = [
                'present' => $present,
                'absent'  => $absent,
                'excused' => $excused,
                'counted' => $counted,
                'pct'     => $counted > 0 ? (int) round($present / $counted * 100) : null,
            ];
        }

        return $rates;
    }
}
