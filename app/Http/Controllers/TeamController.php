<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\PcrTargetAssignment;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\PcrWorkflow;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'nullable|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $user = $request->user();

        if (! $this->mayOpen($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $year = ($data['school_year_id'] ?? null)
            ? SchoolYear::find($data['school_year_id'])
            : SchoolYear::where('is_active', true)->first() ?? SchoolYear::orderByDesc('start_date')->first();

        if (! $year) {
            return response()->json(['year' => null, 'period' => null, 'units' => [], 'members' => []]);
        }

        $period = ($data['rating_period_id'] ?? null)
            ? RatingPeriod::where('school_year_id', $year->id)->find($data['rating_period_id'])
            : RatingPeriod::where('school_year_id', $year->id)->where('is_active', true)->first()
                ?? RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->first();

        $units = $this->unitsFor($user);

        if ($units->isEmpty()) {
            return response()->json([
                'year'    => ['id' => $year->id, 'label' => $year->label],
                'period'  => $period ? ['id' => $period->id, 'label' => $period->label] : null,
                'units'   => [],
                'members' => [],
            ]);
        }

        $members = User::where('status', 'active')
            ->whereIn('org_unit_id', $units->pluck('id'))
            ->where('role', '!=', 'admin')
            ->where('id', '!=', $user->id)
            ->orderByPerson()
            ->get(['id', 'name', 'role', 'position_title', 'image', 'org_unit_id']);

        $forms = PcrForm::where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->where(fn ($q) => $period
                ? $q->where('rating_period_id', $period->id)
                : $q->whereNull('rating_period_id'))
            ->whereIn('user_id', $members->pluck('id'))
            ->withCount('outputs')
            ->get()
            ->keyBy('user_id');

        $summaries = PcrPeriodSummary::whereIn('form_id', $forms->pluck('id'))->get()->keyBy('form_id');
        $unitsById = $units->keyBy('id');

        return response()->json([
            'year'    => ['id' => $year->id, 'label' => $year->label],
            'period'  => $period ? ['id' => $period->id, 'label' => $period->label] : null,
            'units'   => $units->map(fn ($unit) => [
                'id'   => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
                'as'   => $this->capacityOn($unit, $user),
            ])->values(),
            'members' => $members->map(function ($member) use ($forms, $summaries, $unitsById) {
                $form    = $forms->get($member->id);
                $summary = $form ? $summaries->get($form->id) : null;
                $unit    = $unitsById->get($member->org_unit_id);

                return [
                    'id'             => $member->id,
                    'name'           => $member->name,
                    'role'           => $member->role,
                    'position_title' => $member->position_title,
                    'image'          => $member->image,
                    'org_unit'       => $unit ? ['id' => $unit->id, 'name' => $unit->name, 'code' => $unit->code] : null,
                    'form'           => $form ? [
                        'id'            => $form->id,
                        'status'        => $form->status,
                        'outputs_count' => $form->outputs_count,
                        'submitted_at'  => $form->submitted_at,
                        'average'       => $summary?->final_average !== null ? (float) $summary->final_average : null,
                        'adjectival'    => $summary?->adjectival,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    /**
     * Ask a teammate who has no IPCR yet to start one. Same once-a-day rule as
     * the form nudge — the inbox should not stack duplicates for the same ask.
     */
    public function remindStart(Request $request, $userId)
    {
        $actor = $request->user();

        if (! PcrWorkflow::canFollowUp($actor)) {
            return response()->json([
                'message' => 'Only college monitors and people who lead a team can send this follow-up.',
            ], 403);
        }

        $data = $request->validate([
            'school_year_id'   => 'nullable|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $member = User::where('status', 'active')->findOrFail($userId);

        if (! PcrWorkflow::canFollowUpWith($actor, $member)) {
            return response()->json([
                'message' => 'That person is not on a team you follow up with.',
            ], 403);
        }

        $year = ($data['school_year_id'] ?? null)
            ? SchoolYear::find($data['school_year_id'])
            : SchoolYear::where('is_active', true)->first() ?? SchoolYear::orderByDesc('start_date')->first();

        if (! $year) {
            return response()->json(['message' => 'No school year is set up yet.'], 422);
        }

        $period = ($data['rating_period_id'] ?? null)
            ? RatingPeriod::where('school_year_id', $year->id)->find($data['rating_period_id'])
            : RatingPeriod::where('school_year_id', $year->id)->where('is_active', true)->first()
                ?? RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->first();

        if ($period && ($message = PcrWorkflow::lockMessage($actor, (int) $period->id))) {
            return response()->json([
                'message' => 'This review period is locked. Unlock it before sending a follow-up.',
            ], 409);
        }

        $alreadyHas = PcrForm::query()
            ->where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->where('user_id', $member->id)
            ->where(fn ($q) => $period
                ? $q->where('rating_period_id', $period->id)
                : $q->whereNull('rating_period_id'))
            ->exists();

        if ($alreadyHas) {
            return response()->json([
                'message' => 'They already have an IPCR for this period. Open it and nudge from there.',
            ], 422);
        }

        $link = '/my-ipcr';
        $already = Notification::query()
            ->where('type', 'nudge')
            ->where('user_id', $member->id)
            ->where('actor_id', $actor->id)
            ->whereNull('form_id')
            ->where('link', $link)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($already) {
            return response()->json([
                'message' => 'A follow-up was already sent to this person today.',
            ], 409);
        }

        $when = $period?->label ?? $year->label;
        $title = 'Start your IPCR';
        $body = "{$actor->name} asked you to start your IPCR for {$when}.";

        Notification::send($member->id, 'nudge', $title, $body, null, $link);
        ActivityLog::record(
            'User',
            $member->id,
            'nudge',
            "Asked {$member->name} to start an IPCR for {$when}"
        );

        return response()->json([
            'data'    => 'nudged',
            'message' => 'Follow-up sent.',
        ]);
    }

    private function unitsFor(User $user)
    {
        $query = OrgUnit::query()->with(['reviewers', 'viewers'])->orderBy('name');

        // Direct team only — offices this person leads or watches. Campus-wide
        // follow-up for college monitors lives on Forms → All, not here.
        $ids = array_values(array_unique(array_merge(
            OrgUnit::query()->whereLedBy($user->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            OrgUnit::watchedIds((int) $user->id)
        )));

        if ($ids === []) {
            return $query->whereRaw('0 = 1')->get(['id', 'name', 'code']);
        }

        return $query->whereIn('id', $ids)->get(['id', 'name', 'code']);
    }

    private function capacityOn(OrgUnit $unit, User $user): string
    {
        $held = ($unit->relationLoaded('reviewers') ? $unit->reviewers : $unit->reviewers()->get())
            ->filter(fn ($row) => (int) $row->user_id === (int) $user->id)
            ->pluck('slot');

        foreach ([
            'head_user_id'    => 'head',
            'dean_user_id'    => 'dean',
            'vp_user_id'      => 'vp',
            'director_review' => 'director',
        ] as $slot => $label) {
            if ($held->contains($slot)) {
                return $label;
            }
        }

        $views = $unit->relationLoaded('viewers') ? $unit->viewers : $unit->viewers()->get();

        if ($views->contains(fn ($row) => (int) $row->user_id === (int) $user->id)) {
            return 'viewer';
        }

        return 'vp';
    }

    /**
     * Commitment progress for the offices this person leads, including every
     * unit under them. A head of one office sees each person. A dean, director,
     * or VP who has offices underneath sees one row per office, already rolled
     * up the same way the college report rolls a unit into its parent.
     */
    public function progress(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'nullable|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $user = $request->user();

        if (! $this->mayOpen($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $year = ($data['school_year_id'] ?? null)
            ? SchoolYear::find($data['school_year_id'])
            : SchoolYear::where('is_active', true)->first() ?? SchoolYear::orderByDesc('start_date')->first();

        if (! $year) {
            return response()->json($this->emptyProgress(null, null));
        }

        $period = ($data['rating_period_id'] ?? null)
            ? RatingPeriod::where('school_year_id', $year->id)->find($data['rating_period_id'])
            : null;

        $ledIds = OrgUnit::query()->whereLedBy($user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $overseen = OrgUnit::overseenIds((int) $user->id);

        if ($ledIds === [] || $overseen === []) {
            return response()->json($this->emptyProgress($year, $period));
        }

        $units = OrgUnit::query()
            ->whereIn('id', $overseen)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'parent_id']);

        $members = User::where('status', 'active')
            ->whereIn('org_unit_id', $overseen)
            ->where('role', '!=', 'admin')
            ->where('id', '!=', $user->id)
            ->orderByPerson()
            ->get(['id', 'name', 'position_title', 'image', 'org_unit_id']);

        $stats = $this->personProgress($members, $year, $period);
        $unitsById = $units->keyBy('id');

        $parentOf = [];
        $childrenOf = [];

        foreach ($units as $unit) {
            $id = (int) $unit->id;
            $parentOf[$id] = $unit->parent_id ? (int) $unit->parent_id : null;

            if ($unit->parent_id && $unitsById->has((int) $unit->parent_id)) {
                $childrenOf[(int) $unit->parent_id][] = $id;
            }
        }

        $led = array_fill_keys($ledIds, true);
        $roots = [];

        foreach ($ledIds as $id) {
            $parent = $parentOf[$id] ?? null;
            $guard = [];
            $underLed = false;

            while ($parent && ! isset($guard[$parent])) {
                if (isset($led[$parent])) {
                    $underLed = true;
                    break;
                }

                $guard[$parent] = true;
                $parent = $parentOf[$parent] ?? null;
            }

            if (! $underLed) {
                $roots[] = $id;
            }
        }

        $nested = false;

        foreach ($roots as $root) {
            if (! empty($childrenOf[$root])) {
                $nested = true;
                break;
            }
        }

        $directPct = $this->directPcts($members, $stats);
        $rows = $nested
            ? $this->officeRows($roots, $childrenOf, $unitsById, $members, $stats, $directPct)
            : $this->peopleRows($members, $unitsById, $stats);

        $measured = array_values(array_filter(
            array_column($rows, 'progress_pct'),
            fn ($pct) => $pct !== null
        ));

        $started = 0;

        foreach ($members as $member) {
            if (($stats[$member->id]['commitments'] ?? 0) > 0) {
                $started++;
            }
        }

        return response()->json([
            'year'   => ['id' => $year->id, 'label' => $year->label],
            'period' => $period ? ['id' => $period->id, 'label' => $period->label] : null,
            'view'   => $nested ? 'units' : 'people',
            'totals' => [
                'people'       => $members->count(),
                'started'      => $started,
                'progress_pct' => $measured === [] ? null : (int) round(array_sum($measured) / count($measured)),
                'commitments'  => array_sum(array_column($rows, 'commitments')),
                'completed'    => array_sum(array_column($rows, 'completed')),
                'overdue'      => array_sum(array_column($rows, 'overdue')),
            ],
            'rows' => $rows,
        ]);
    }

    private function mayOpen(User $user): bool
    {
        return PcrWorkflow::canFollowUp($user)
            || OrgUnit::watchedIds((int) $user->id) !== [];
    }

    private function emptyProgress(?SchoolYear $year, ?RatingPeriod $period): array
    {
        return [
            'year'   => $year ? ['id' => $year->id, 'label' => $year->label] : null,
            'period' => $period ? ['id' => $period->id, 'label' => $period->label] : null,
            'view'   => 'people',
            'totals' => [
                'people'       => 0,
                'started'      => 0,
                'progress_pct' => null,
                'commitments'  => 0,
                'completed'    => 0,
                'overdue'      => 0,
            ],
            'rows' => [],
        ];
    }

    /**
     * One average per person, taken from the leaf lines on their IPCR. A line
     * that still has children, or that was handed out as an office target,
     * is the parent's copy and is left out — the same cut the college report uses.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $members
     * @return array<int, array{progress_pct: ?int, commitments: int, completed: int, overdue: int, form_id: ?int}>
     */
    private function personProgress($members, SchoolYear $year, ?RatingPeriod $period): array
    {
        $stats = [];

        foreach ($members as $member) {
            $stats[$member->id] = [
                'progress_pct' => null,
                'commitments'  => 0,
                'completed'    => 0,
                'overdue'      => 0,
                'form_id'      => null,
                'drafting'     => 1,
                'submitted'    => 0,
                'rated'        => 0,
            ];
        }

        if ($members->isEmpty()) {
            return $stats;
        }

        $forms = PcrForm::query()
            ->where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->whereIn('user_id', $members->pluck('id'))
            ->when(
                $period,
                fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('rating_period_id')->orWhere('rating_period_id', $period->id)
                )
            )
            ->get(['id', 'user_id', 'status']);

        foreach ($forms->groupBy('user_id') as $userId => $theirs) {
            $userId = (int) $userId;

            if (! isset($stats[$userId])) {
                continue;
            }

            $latest = $theirs->sortByDesc('id')->first();
            $stage = $this->stageCounts($latest->status);
            $stats[$userId]['form_id'] = (int) $latest->id;
            $stats[$userId]['drafting'] = $stage['drafting'];
            $stats[$userId]['submitted'] = $stage['submitted'];
            $stats[$userId]['rated'] = $stage['rated'];
        }

        if ($forms->isEmpty()) {
            return $stats;
        }

        $outputs = PcrOutput::whereIn('form_id', $forms->pluck('id'))->get(['id', 'form_id']);
        $formUser = $forms->pluck('user_id', 'id');

        $indicators = PcrIndicator::whereIn('output_id', $outputs->pluck('id'))
            ->when(
                $period,
                fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('rating_period_id')->orWhere('rating_period_id', $period->id)
                )
            )
            ->get(['id', 'output_id', 'target_date', 'progress_pct', 'progress_status']);

        $parents = PcrIndicator::whereIn('parent_indicator_id', $indicators->pluck('id'))
            ->distinct()
            ->pluck('parent_indicator_id')
            ->flip();

        $assigned = PcrTargetAssignment::whereIn('indicator_id', $indicators->pluck('id'))
            ->pluck('indicator_id')
            ->flip();

        $outputForm = $outputs->pluck('form_id', 'id');
        $today = now()->startOfDay();
        $pcts = [];

        foreach ($indicators as $line) {
            if ($parents->has($line->id) || $assigned->has($line->id)) {
                continue;
            }

            $formId = $outputForm[$line->output_id] ?? null;
            $userId = $formId ? (int) ($formUser[$formId] ?? 0) : 0;

            if (! isset($stats[$userId])) {
                continue;
            }

            $done = $line->progress_status === 'completed';
            $due = $line->target_date;
            $late = $due && ! $done && $due->copy()->startOfDay()->lt($today);

            $stats[$userId]['commitments']++;
            $stats[$userId]['completed'] += $done ? 1 : 0;
            $stats[$userId]['overdue'] += $late ? 1 : 0;
            $pcts[$userId][] = (int) ($line->progress_pct ?? 0);
        }

        foreach ($pcts as $userId => $values) {
            $stats[$userId]['progress_pct'] = (int) round(array_sum($values) / count($values));
        }

        return $stats;
    }

    /** Same three bands as the college chart: still drafting, sent on, or rated. */
    private function stageCounts(?string $status): array
    {
        if ($status === null || $status === 'draft') {
            return ['drafting' => 1, 'submitted' => 0, 'rated' => 0];
        }

        if (in_array($status, ['rated', 'final'], true)) {
            return ['drafting' => 0, 'submitted' => 0, 'rated' => 1];
        }

        return ['drafting' => 0, 'submitted' => 1, 'rated' => 0];
    }

    /**
     * @param  array<int, int>  $roots
     * @param  array<int, array<int, int>>  $childrenOf
     */
    private function officeRows(array $roots, array $childrenOf, $unitsById, $members, array $stats, array $directPct): array
    {
        $rows = [];

        foreach ($roots as $root) {
            $children = $childrenOf[$root] ?? [];

            if ($children === []) {
                $unit = $unitsById->get($root);

                if ($unit) {
                    $rows[] = $this->unitRow($unit, $this->subtreeIds($root, $childrenOf), $members, $stats, $childrenOf, $directPct, false);
                }

                continue;
            }

            $direct = $members->filter(fn ($member) => (int) $member->org_unit_id === $root);

            if ($direct->isNotEmpty()) {
                $unit = $unitsById->get($root);

                if ($unit) {
                    $rows[] = $this->unitRow($unit, [$root], $members, $stats, $childrenOf, $directPct, true);
                }
            }

            foreach ($children as $childId) {
                $unit = $unitsById->get($childId);

                if ($unit) {
                    $rows[] = $this->unitRow($unit, $this->subtreeIds($childId, $childrenOf), $members, $stats, $childrenOf, $directPct, false);
                }
            }
        }

        return $this->sortByPace($rows);
    }

    /**
     * @param  array<int, int>  $unitIds
     * @param  array<int, array<int, int>>  $childrenOf
     * @param  array<int, int>  $directPct
     */
    private function unitRow(OrgUnit $unit, array $unitIds, $members, array $stats, array $childrenOf, array $directPct, bool $directOnly): array
    {
        $wanted = array_fill_keys($unitIds, true);
        $people = $members->filter(fn ($member) => isset($wanted[(int) $member->org_unit_id]));
        $summary = $this->tally($people, $stats);

        if (! $directOnly && count($unitIds) > 1) {
            $summary['progress_pct'] = $this->rolledPct((int) $unit->id, $childrenOf, $directPct);
        }

        return [
            'kind'          => 'unit',
            'id'            => $directOnly ? 'office-'.$unit->id : $unit->id,
            'name'          => $unit->name,
            'code'          => $unit->code,
            'direct'        => $directOnly,
            'people'        => $people->count(),
            'progress_pct'  => $summary['progress_pct'],
            'commitments'   => $summary['commitments'],
            'completed'     => $summary['completed'],
            'overdue'       => $summary['overdue'],
            'drafting'      => $summary['drafting'],
            'submitted'     => $summary['submitted'],
            'rated'         => $summary['rated'],
        ];
    }

    private function peopleRows($members, $unitsById, array $stats): array
    {
        $rows = $members->map(function ($member) use ($unitsById, $stats) {
            $unit = $unitsById->get($member->org_unit_id);
            $row = $stats[$member->id];

            return [
                'kind'           => 'person',
                'id'             => $member->id,
                'name'           => $member->name,
                'position_title' => $member->position_title,
                'image'          => $member->image,
                'unit'           => $unit?->name,
                'unit_code'      => $unit?->code,
                'form_id'        => $row['form_id'],
                'progress_pct'   => $row['progress_pct'],
                'commitments'    => $row['commitments'],
                'completed'      => $row['completed'],
                'overdue'        => $row['overdue'],
                'drafting'       => $row['drafting'],
                'submitted'      => $row['submitted'],
                'rated'          => $row['rated'],
            ];
        })->all();

        return $this->sortByPace($rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $people
     * @return array{progress_pct: ?int, commitments: int, completed: int, overdue: int}
     */
    private function tally($people, array $stats): array
    {
        $pcts = [];
        $commitments = 0;
        $completed = 0;
        $overdue = 0;
        $drafting = 0;
        $submitted = 0;
        $rated = 0;

        foreach ($people as $person) {
            $row = $stats[$person->id] ?? null;

            if (! $row) {
                continue;
            }

            $commitments += $row['commitments'];
            $completed += $row['completed'];
            $overdue += $row['overdue'];
            $drafting += $row['drafting'];
            $submitted += $row['submitted'];
            $rated += $row['rated'];

            if ($row['progress_pct'] !== null) {
                $pcts[] = $row['progress_pct'];
            }
        }

        return [
            'progress_pct' => $pcts === [] ? null : (int) round(array_sum($pcts) / count($pcts)),
            'commitments'  => $commitments,
            'completed'    => $completed,
            'overdue'      => $overdue,
            'drafting'     => $drafting,
            'submitted'    => $submitted,
            'rated'        => $rated,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function directPcts($members, array $stats): array
    {
        $grouped = [];

        foreach ($members as $member) {
            $pct = $stats[$member->id]['progress_pct'] ?? null;

            if ($pct !== null) {
                $grouped[(int) $member->org_unit_id][] = $pct;
            }
        }

        $pcts = [];

        foreach ($grouped as $unitId => $values) {
            $pcts[$unitId] = (int) round(array_sum($values) / count($values));
        }

        return $pcts;
    }

    /**
     * Each child office counts once, already rolled up, so one faculty line is
     * not averaged again at every level above it.
     *
     * @param  array<int, array<int, int>>  $childrenOf
     * @param  array<int, int>  $directPct
     */
    private function rolledPct(int $id, array $childrenOf, array $directPct): ?int
    {
        $memo = [];

        $visit = function (int $current, array $stack) use (&$visit, &$memo, $childrenOf, $directPct): ?int {
            if (array_key_exists($current, $memo)) {
                return $memo[$current];
            }

            if (isset($stack[$current])) {
                return $directPct[$current] ?? null;
            }

            $stack[$current] = true;
            $parts = [];

            if (array_key_exists($current, $directPct)) {
                $parts[] = $directPct[$current];
            }

            foreach ($childrenOf[$current] ?? [] as $childId) {
                $child = $visit((int) $childId, $stack);

                if ($child !== null) {
                    $parts[] = $child;
                }
            }

            return $memo[$current] = ($parts === [] ? null : (int) round(array_sum($parts) / count($parts)));
        };

        return $visit($id, []);
    }

    /**
     * @param  array<int, array<int, int>>  $childrenOf
     * @return array<int, int>
     */
    private function subtreeIds(int $root, array $childrenOf): array
    {
        $ids = [];
        $stack = [$root];

        while ($stack) {
            $id = array_pop($stack);

            if (isset($ids[$id])) {
                continue;
            }

            $ids[$id] = true;

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_keys($ids);
    }

    private function sortByPace(array $rows): array
    {
        usort($rows, function (array $left, array $right) {
            $a = $left['progress_pct'];
            $b = $right['progress_pct'];

            if ($a === null && $b !== null) {
                return -1;
            }

            if ($b === null && $a !== null) {
                return 1;
            }

            if ($a !== $b) {
                return $a <=> $b;
            }

            return strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return array_values($rows);
    }
}
