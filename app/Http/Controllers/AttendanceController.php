<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AttendanceSync;
use App\Models\AttendanceDay;
use App\Models\AttendanceMeeting;
use App\Models\AttendanceMeetingMark;
use App\Models\AttendanceRate;
use App\Models\OrgUnit;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $this->forThisAccount($request->user());

        $data = $request->validate([
            'school_year_id'   => 'nullable|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
            'month'            => 'nullable|date_format:Y-m',
            'org_unit_id'      => 'nullable|integer|exists:org_units,id',
        ]);

        $user    = $request->user();
        $led     = $this->ledUnits($user);
        $catalog = $led;
        $year = $this->year($data['school_year_id'] ?? null);

        if (! $year || $catalog->isEmpty()) {
            if ($user->role === 'employee' && $year) {
                return response()->json($this->personalRecord($user, $year, $data));
            }

            return response()->json([
                'year'             => $year ? ['id' => $year->id, 'label' => $year->label] : null,
                'period'           => null,
                'watching'         => false,
                'readonly'         => false,
                'selected_unit_id' => null,
                'units'            => [],
                'record_units'     => [],
                'categories'       => AttendanceMeeting::categoryList(),
                'members'          => [],
                'months'           => [],
                'month'            => null,
                'days'             => [],
                'daily'            => (object) [],
                'daily_rates'      => (object) [],
                'category_rates'   => (object) [],
                'meetings'         => [],
            ]);
        }

        $focus    = $catalog;
        $readonly = false;

        $period = $this->period($year, $data['rating_period_id'] ?? null);
        $span   = $period ? AttendanceDay::periodSpan($period) : null;
        $days   = $span ? AttendanceDay::days($span[0], $span[1]) : [];
        $months = $this->months($days);
        $month  = $this->chosenMonth($months, $data['month'] ?? null);
        $shown  = array_values(array_filter($days, fn (string $day) => str_starts_with($day, $month)));

        $subtreeFor = [];

        foreach ($focus as $unit) {
            $subtreeFor[$unit->id] = OrgUnit::subtreeIds([(int) $unit->id]);
        }

        $memberUnitIds = array_values(array_unique(array_merge(...array_values($subtreeFor))));
        $members       = $this->peopleIn($memberUnitIds, $user);

        $daily = [];

        if ($period && $members->isNotEmpty() && $shown !== []) {
            $marks = AttendanceDay::query()
                ->where('rating_period_id', $period->id)
                ->whereIn('user_id', $members->pluck('id'))
                ->get(['user_id', 'day', 'status']);

            foreach ($marks as $mark) {
                $date = $mark->day->toDateString();

                if (in_array($date, $shown, true)) {
                    $daily[$mark->user_id][$date] = $mark->status;
                }
            }
        }

        $rates = [];
        $activityRates = [];
        $categoryRates = [];

        if ($period) {
            foreach ($members as $member) {
                AttendanceRate::remember((int) $member->id, (int) $period->id);
            }

            $rates = AttendanceRate::forPeriod((int) $period->id, 'daily');
            $activityRates = AttendanceRate::forPeriod((int) $period->id, 'activities');
            $categoryRates = AttendanceRate::categoriesForPeriod((int) $period->id);
        }

        $meetings = $period
            ? AttendanceMeeting::query()
                ->when(
                    $readonly,
                    fn ($query) => $query->whereIn('org_unit_id', array_values(array_unique(array_merge(...array_values($subtreeFor))))),
                    fn ($query) => $query->where('created_by', $user->id)
                )
                ->where('rating_period_id', $period->id)
                ->with(['marks', 'unit:id,name,code'])
                ->orderByDesc('held_on')
                ->orderByDesc('id')
                ->get()
            : collect();

        return response()->json([
            'year'        => ['id' => $year->id, 'label' => $year->label],
            'period'      => $period ? [
                'id'        => $period->id,
                'label'     => $period->label,
                'locked'    => $period->isLockedFor($user),
                'opens_at'  => $span ? $span[0]->toDateString() : null,
                'closes_at' => $span ? $span[1]->toDateString() : null,
            ] : null,
            'watching'         => false,
            'readonly'         => $readonly,
            'selected_unit_id' => null,
            'units'            => $catalog->map(fn (OrgUnit $unit) => [
                'id'   => $unit->id,
                'name' => $unit->name,
            ])->values(),
            'record_units'     => $led->map(fn (OrgUnit $unit) => [
                'id'   => $unit->id,
                'name' => $unit->name,
            ])->values(),
            'categories'  => AttendanceMeeting::categoryList(),
            'members'     => $members->map(fn (User $member) => [
                'id'             => $member->id,
                'name'           => $member->name,
                'position_title' => $member->position_title,
                'image'          => $member->image,
                'org_unit_id'    => $member->org_unit_id,
            ])->values(),
            'months'      => $months,
            'month'       => $month,
            'days'        => $shown,
            'daily'       => $daily ?: (object) [],
            'daily_rates' => $rates ?: (object) [],
            'activity_rates' => $activityRates ?: (object) [],
            'category_rates' => $categoryRates ?: (object) [],
            'meetings'    => $meetings->map(function (AttendanceMeeting $meeting) use ($members) {
                $allowed = array_flip(OrgUnit::subtreeIds([(int) $meeting->org_unit_id]));
                $marks   = [];

                foreach ($meeting->marks as $mark) {
                    $marks[$mark->user_id] = $mark->status;
                }

                return [
                    'id'             => $meeting->id,
                    'title'          => $meeting->title,
                    'category'       => $meeting->category,
                    'category_label' => AttendanceMeeting::CATEGORIES[$meeting->category] ?? $meeting->category,
                    'held_on'        => $meeting->held_on?->toDateString(),
                    'unit'           => $meeting->unit ? ['id' => $meeting->unit->id, 'name' => $meeting->unit->name] : null,
                    'people'         => $members
                        ->filter(fn (User $member) => isset($allowed[(int) $member->org_unit_id]))
                        ->pluck('id')
                        ->values(),
                    'marks'          => $marks ?: (object) [],
                    'rate'           => tap($meeting, fn (AttendanceMeeting $meeting) => $meeting->refreshSavedRate())->savedRate(),
                ];
            })->values(),
        ]);
    }

    public function markDay(Request $request)
    {
        $this->forThisAccount($request->user());

        $data = $request->validate([
            'rating_period_id' => 'required|integer|exists:rating_periods,id',
            'user_id'          => 'required|integer|exists:users,id',
            'day'              => 'required|date',
            'status'           => 'nullable|in:' . implode(',', AttendanceDay::STATUSES),
        ]);

        $user   = $request->user();
        $period = $this->openPeriod($user, (int) $data['rating_period_id']);
        $person = $this->rosterPerson($user, (int) $data['user_id']);
        $day    = Carbon::parse($data['day'])->startOfDay();
        $span   = AttendanceDay::periodSpan($period);

        if (! $span || $day->lt($span[0]) || $day->gt($span[1])) {
            return response()->json(['message' => 'That day is outside this rating period.'], 422);
        }

        $this->writeDay((int) $person->id, $day->toDateString(), empty($data['status']) ? null : $data['status'], (int) $period->id, (int) $user->id);
        AttendanceRate::remember((int) $person->id, (int) $period->id);

        return response()->json([
            'rate' => AttendanceDay::rateFor((int) $person->id, (int) $period->id),
        ]);
    }

    public function storeMeeting(Request $request)
    {
        $this->forThisAccount($request->user());

        $data    = $this->validateMeeting($request, true);
        $user    = $request->user();
        $period  = $this->openPeriod($user, (int) $data['rating_period_id']);
        $unit    = $this->ledUnit($user, (int) $data['org_unit_id']);
        $heldOn  = Carbon::parse($data['held_on'])->startOfDay();
        $this->dateInside($period, $heldOn, 'That meeting is outside this rating period.');

        $meeting = AttendanceMeeting::create([
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period->id,
            'created_by'       => $user->id,
            'title'            => $data['title'],
            'category'         => $data['category'],
            'held_on'          => $heldOn->toDateString(),
        ]);

        ActivityLog::record('AttendanceMeeting', $meeting->id, 'create', "Set {$meeting->title}");

        return response()->json(['meeting' => ['id' => $meeting->id]], 201);
    }

    public function updateMeeting(Request $request, $id)
    {
        $this->forThisAccount($request->user());

        $data    = $this->validateMeeting($request, false);
        $meeting = $this->ownMeeting($request, $id);
        $period  = $this->openPeriod($request->user(), (int) $meeting->rating_period_id);
        $heldOn  = Carbon::parse($data['held_on'])->startOfDay();
        $this->dateInside($period, $heldOn, 'That meeting is outside this rating period.');

        $meeting->fill([
            'title'    => $data['title'],
            'category' => $data['category'],
            'held_on'  => $heldOn->toDateString(),
        ])->save();

        ActivityLog::record('AttendanceMeeting', $meeting->id, 'update', "Updated {$meeting->title}");

        return response()->json(['data' => 'updated']);
    }

    public function destroyMeeting(Request $request, $id)
    {
        $this->forThisAccount($request->user());

        $meeting = $this->ownMeeting($request, $id);
        $this->openPeriod($request->user(), (int) $meeting->rating_period_id);
        $title    = $meeting->title;
        $periodId = (int) $meeting->rating_period_id;
        $day      = $meeting->held_on->toDateString();
        $people   = $meeting->marks()->pluck('user_id');
        $meeting->delete();

        foreach ($people as $personId) {
            $this->syncDayFromActivities((int) $request->user()->id, (int) $personId, $periodId, $day);
            AttendanceRate::remember((int) $personId, $periodId);
        }

        ActivityLog::record('AttendanceMeeting', (int) $id, 'delete', "Removed {$title}");

        return response()->json(['data' => 'deleted']);
    }

    public function markMeeting(Request $request, $id)
    {
        $this->forThisAccount($request->user());

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'status'  => 'nullable|in:' . implode(',', AttendanceMeetingMark::STATUSES),
        ]);

        $meeting = $this->ownMeeting($request, $id);
        $this->openPeriod($request->user(), (int) $meeting->rating_period_id);
        $person  = $this->rosterPerson($request->user(), (int) $data['user_id']);
        $allowed = OrgUnit::subtreeIds([(int) $meeting->org_unit_id]);

        if (! in_array((int) $person->org_unit_id, $allowed, true)) {
            return response()->json(['message' => 'That person is not in this meeting\'s office.'], 403);
        }

        $where = [
            'meeting_id' => $meeting->id,
            'user_id'    => $person->id,
        ];

        if (empty($data['status'])) {
            AttendanceMeetingMark::query()->where($where)->delete();
        } else {
            AttendanceMeetingMark::updateOrCreate($where, ['status' => $data['status']]);
        }

        $this->syncDayFromActivities(
            (int) $request->user()->id,
            (int) $person->id,
            (int) $meeting->rating_period_id,
            $meeting->held_on->toDateString(),
        );
        $meeting->refreshSavedRate();
        AttendanceRate::remember((int) $person->id, (int) $meeting->rating_period_id);

        return response()->json(['data' => 'saved']);
    }

    /**
     * Mark everyone in the activity's office present (or another status) in one
     * pass. The head can then tweak the few who were absent or excused.
     */
    public function markMeetingAll(Request $request, $id)
    {
        $this->forThisAccount($request->user());

        $data = $request->validate([
            'status' => 'nullable|in:' . implode(',', AttendanceMeetingMark::STATUSES),
        ]);

        $status  = $data['status'] ?? 'present';
        $user    = $request->user();
        $meeting = $this->ownMeeting($request, $id);
        $this->openPeriod($user, (int) $meeting->rating_period_id);

        $people = $this->peopleIn(OrgUnit::subtreeIds([(int) $meeting->org_unit_id]), $user);
        $day    = $meeting->held_on->toDateString();
        $periodId = (int) $meeting->rating_period_id;

        foreach ($people as $person) {
            AttendanceMeetingMark::updateOrCreate(
                [
                    'meeting_id' => $meeting->id,
                    'user_id'    => $person->id,
                ],
                ['status' => $status],
            );

            $this->syncDayFromActivities((int) $user->id, (int) $person->id, $periodId, $day);
            AttendanceRate::remember((int) $person->id, $periodId);
        }

        $meeting->refreshSavedRate();

        return response()->json([
            'data'   => 'saved',
            'marked' => $people->count(),
            'rate'   => $meeting->fresh()->savedRate(),
        ]);
    }

    /**
     * An activity mark sets that calendar day. Present wins, then absent, then
     * excused, which is stored as leave so it stays on the sheet and out of the
     * daily percentage.
     *
     * A day already marked leave on the daily sheet is left alone. Meeting marks
     * still count toward activity rates; changing leave is a manual daily edit.
     */
    private function syncDayFromActivities(int $actorId, int $personId, int $periodId, string $day): void
    {
        AttendanceSync::dayFromActivities($actorId, $personId, $periodId, $day);
    }

    private function writeDay(int $personId, string $day, ?string $status, int $periodId, int $actorId): void
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

    private function validateMeeting(Request $request, bool $creating): array
    {
        return $request->validate([
            'org_unit_id'      => ($creating ? 'required' : 'prohibited') . '|integer|exists:org_units,id',
            'rating_period_id' => ($creating ? 'required' : 'prohibited') . '|integer|exists:rating_periods,id',
            'title'            => 'required|string|max:200',
            'category'         => 'required|in:' . implode(',', array_keys(AttendanceMeeting::CATEGORIES)),
            'held_on'          => 'required|date',
        ]);
    }

    /**
     * The signed-in employee's own days. They can read the record. The head still writes it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personalRecord(User $user, SchoolYear $year, array $data): array
    {
        $period = $this->period($year, isset($data['rating_period_id']) ? (int) $data['rating_period_id'] : null);
        $span   = $period ? AttendanceDay::periodSpan($period) : null;
        $days   = $span ? AttendanceDay::days($span[0], $span[1]) : [];
        $months = $this->months($days);
        $month  = $this->chosenMonth($months, $data['month'] ?? null);
        $shown  = array_values(array_filter($days, fn (string $day) => str_starts_with($day, (string) $month)));
        $daily  = [];

        if ($period && $shown !== []) {
            $marks = AttendanceDay::query()
                ->where('rating_period_id', $period->id)
                ->where('user_id', $user->id)
                ->get(['day', 'status']);

            foreach ($marks as $mark) {
                $date = $mark->day->toDateString();

                if (in_array($date, $shown, true)) {
                    $daily[$user->id][$date] = $mark->status;
                }
            }
        }

        $rates = [];
        $activityRates = [];
        $categoryRates = [];

        if ($period) {
            AttendanceRate::remember((int) $user->id, (int) $period->id);
            $only = [(int) $user->id => true];
            $rates = array_intersect_key(AttendanceRate::forPeriod((int) $period->id, 'daily'), $only);
            $activityRates = array_intersect_key(AttendanceRate::forPeriod((int) $period->id, 'activities'), $only);
            $categoryRates = array_intersect_key(AttendanceRate::categoriesForPeriod((int) $period->id), $only);
        }

        $unit = $user->org_unit_id ? OrgUnit::query()->find($user->org_unit_id) : null;

        return [
            'year'   => ['id' => $year->id, 'label' => $year->label],
            'period' => $period ? [
                'id'        => $period->id,
                'label'     => $period->label,
                'locked'    => $period->isLockedFor($user),
                'opens_at'  => $span ? $span[0]->toDateString() : null,
                'closes_at' => $span ? $span[1]->toDateString() : null,
            ] : null,
            'watching'         => false,
            'readonly'         => true,
            'selected_unit_id' => null,
            'units'            => $unit ? [['id' => $unit->id, 'name' => $unit->name]] : [],
            'record_units'     => [],
            'categories'       => AttendanceMeeting::categoryList(),
            'members'          => [[
                'id'             => $user->id,
                'name'           => $user->name,
                'position_title' => $user->position_title,
                'image'          => $user->image,
                'org_unit_id'    => $user->org_unit_id,
            ]],
            'months'         => $months,
            'month'          => $month,
            'days'           => $shown,
            'daily'          => $daily ?: (object) [],
            'daily_rates'    => $rates ?: (object) [],
            'activity_rates' => $activityRates ?: (object) [],
            'category_rates' => $categoryRates ?: (object) [],
            'meetings'       => [],
        ];
    }

    private function year(?int $id): ?SchoolYear
    {
        return $id
            ? SchoolYear::find($id)
            : SchoolYear::where('is_active', true)->first() ?? SchoolYear::orderByDesc('start_date')->first();
    }

    private function period(SchoolYear $year, ?int $id): ?RatingPeriod
    {
        return $id
            ? RatingPeriod::where('school_year_id', $year->id)->find($id)
            : RatingPeriod::where('school_year_id', $year->id)->where('is_active', true)->first()
                ?? RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->first();
    }

    /**
     * @param  array<int, string>  $days
     * @return array<int, string>
     */
    private function months(array $days): array
    {
        $months = [];

        foreach ($days as $day) {
            $month = substr($day, 0, 7);

            if (! in_array($month, $months, true)) {
                $months[] = $month;
            }
        }

        return $months;
    }

    /**
     * @param  array<int, string>  $months
     */
    private function chosenMonth(array $months, ?string $requested): ?string
    {
        if ($requested && in_array($requested, $months, true)) {
            return $requested;
        }

        $today = now()->format('Y-m');

        if (in_array($today, $months, true)) {
            return $today;
        }

        return $months[0] ?? null;
    }

    /** A VP or the President has no attendance page on their account. */
    private function forThisAccount(User $user): void
    {
        if (in_array($user->role, ['vp', 'president'], true)) {
            abort(response()->json([
                'message' => 'Attendance is not on this account.',
            ], 403));
        }
    }

    private function ledUnits(User $user): Collection
    {
        return OrgUnit::query()
            ->whereLedBy($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    private function ledUnit(User $user, int $unitId): OrgUnit
    {
        $unit = $this->ledUnits($user)->firstWhere('id', $unitId);

        if (! $unit) {
            abort(response()->json([
                'message' => 'You can only record attendance for an office you head.',
            ], 403));
        }

        return $unit;
    }

    private function openPeriod(User $user, int $periodId): RatingPeriod
    {
        $period = RatingPeriod::findOrFail($periodId);

        if ($period->isLockedFor($user)) {
            abort(response()->json(['message' => 'This rating period is locked.'], 423));
        }

        return $period;
    }

    private function dateInside(RatingPeriod $period, Carbon $day, string $message): void
    {
        $span = AttendanceDay::periodSpan($period);

        if (! $span || $day->lt($span[0]) || $day->gt($span[1])) {
            abort(response()->json(['message' => $message], 422));
        }
    }

    private function rosterPerson(User $user, int $userId): User
    {
        $roots = $this->ledUnits($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids   = OrgUnit::subtreeIds($roots);
        $person = User::query()
            ->where('status', 'active')
            ->where('role', '!=', 'admin')
            ->where('id', '!=', $user->id)
            ->whereIn('org_unit_id', $ids)
            ->when($user->role === 'vp', fn ($query) => $query->where('role', '!=', 'employee'))
            ->find($userId);

        if (! $person) {
            abort(response()->json([
                'message' => $user->role === 'vp'
                    ? 'A VP records attendance for heads, not employees.'
                    : 'That person is not in an office you head.',
            ], 403));
        }

        return $person;
    }

    private function ownMeeting(Request $request, $id): AttendanceMeeting
    {
        return AttendanceMeeting::query()
            ->where('created_by', $request->user()->id)
            ->findOrFail($id);
    }

    private function peopleIn(array $unitIds, User $actor): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return User::query()
            ->where('status', 'active')
            ->whereIn('org_unit_id', $unitIds)
            ->where('role', '!=', 'admin')
            ->where('id', '!=', $actor->id)
            ->when($actor->role === 'vp', fn ($query) => $query->where('role', '!=', 'employee'))
            ->orderByPerson()
            ->get(['id', 'name', 'position_title', 'image', 'org_unit_id', 'role']);
    }
}
