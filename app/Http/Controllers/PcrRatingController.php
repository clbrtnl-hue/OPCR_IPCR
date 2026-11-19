<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\PcrTargetAssignment;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Models\User;
use App\Support\Html;
use App\Services\PcrWorkflow;
use App\Services\RatingScale;
use App\Services\WorkflowSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PcrRatingController extends Controller
{
    public function store(Request $request)
    {
        $scale  = app(WorkflowSettings::class);
        [$min, $max] = $scale->ratingBounds();
        $active = $scale->ratingDimensionKeys();
        $bound  = 'nullable|integer|min:'.(int) $min.'|max:'.(int) $max;

        $data = $request->validate([
            'form_id'              => 'required|integer|exists:pcr_forms,id',
            'rating_period_id'     => 'required|integer|exists:rating_periods,id',
            'ratings'              => 'required|array|min:1',
            'ratings.*.indicator_id' => 'required|integer|exists:pcr_indicators,id',
            'ratings.*.q'          => in_array('q', $active, true) ? $bound : 'nullable',
            'ratings.*.e'          => in_array('e', $active, true) ? $bound : 'nullable',
            'ratings.*.t'          => in_array('t', $active, true) ? $bound : 'nullable',
            'ratings.*.remarks'    => 'nullable|string|max:2000',
        ]);

        $form   = PcrForm::findOrFail($data['form_id']);
        $period = RatingPeriod::findOrFail($data['rating_period_id']);
        $user   = $request->user();

        if ($form->type === 'opcr') {
            if ($message = PcrWorkflow::formLockMessage($user, $form)) {
                return response()->json(['message' => $message], 409);
            }
        } elseif ($period->isLockedFor($user)) {
            return response()->json([
                'message' => "{$period->label} is locked. It is view-only now — ask an administrator to unlock it.",
            ], 409);
        }

        $remarksOnly = $request->boolean('remarks_only');

        if ($remarksOnly) {
            if (! $this->mayLeaveRemarks($user, $form)) {
                return response()->json([
                    'message' => 'You cannot leave remarks on this form.',
                ], 403);
            }
        } elseif (! PcrWorkflow::mayScore($user, $form)) {
            return response()->json([
                'message' => 'This form is not waiting for a rating yet.',
            ], 409);
        }

        if ((int) $period->school_year_id !== (int) $form->school_year_id) {
            return response()->json([
                'message' => 'That review period belongs to a different school year.',
            ], 422);
        }

        if ($form->rating_period_id && (int) $form->rating_period_id !== (int) $period->id) {
            return response()->json([
                'message' => 'This IPCR covers a different review period.',
            ], 422);
        }

        $formIndicatorIds = $this->indicatorIds($form);

        foreach ($data['ratings'] as $row) {
            if (! in_array((int) $row['indicator_id'], $formIndicatorIds, true)) {
                return response()->json([
                    'message' => 'One of those indicators is not part of this form.',
                ], 422);
            }
        }

        DB::transaction(function () use ($data, $period, $user, $active, $remarksOnly) {
            foreach ($data['ratings'] as $row) {
                $remarks = Html::clean($row['remarks'] ?? null);

                if ($remarksOnly) {
                    $existing = PcrRating::where('indicator_id', $row['indicator_id'])
                        ->where('rating_period_id', $period->id)
                        ->first();

                    if ($existing) {
                        $existing->update(['remarks' => $remarks]);

                        continue;
                    }

                    PcrRating::create([
                        'indicator_id'     => $row['indicator_id'],
                        'rating_period_id' => $period->id,
                        'remarks'          => $remarks,
                        'rated_by'         => $user->id,
                        'rated_by_name'    => $user->name,
                    ]);

                    continue;
                }

                $q = in_array('q', $active, true) ? ($row['q'] ?? null) : null;
                $e = in_array('e', $active, true) ? ($row['e'] ?? null) : null;
                $t = in_array('t', $active, true) ? ($row['t'] ?? null) : null;

                PcrRating::updateOrCreate(
                    [
                        'indicator_id'     => $row['indicator_id'],
                        'rating_period_id' => $period->id,
                    ],
                    [
                        'q'             => $q,
                        'e'             => $e,
                        't'             => $t,
                        'a'             => RatingScale::average($q, $e, $t),
                        'remarks'       => $remarks,
                        'rated_by'      => $user->id,
                        'rated_by_name' => $user->name,
                    ]
                );
            }
        });

        return response()->json([
            'data'    => 'saved',
            'summary' => $this->computeSummary($form, $period),
        ]);
    }

    public function finalize(Request $request, $id)
    {
        $data = $request->validate([
            'rating_period_id' => 'required|integer|exists:rating_periods,id',
        ]);

        $form   = PcrForm::findOrFail($id);
        $period = RatingPeriod::findOrFail($data['rating_period_id']);
        $user   = $request->user();

        if ($form->type === 'opcr') {
            if ($message = PcrWorkflow::formLockMessage($user, $form)) {
                return response()->json(['message' => $message], 409);
            }
        } elseif ($period->isLockedFor($user)) {
            return response()->json([
                'message' => "{$period->label} is locked. It is view-only now — ask an administrator to unlock it.",
            ], 409);
        }

        if (! PcrWorkflow::mayFinalize($user, $form)) {
            return response()->json(['message' => 'This form is not waiting for a rating.'], 409);
        }

        if ($form->rating_period_id && (int) $form->rating_period_id !== (int) $period->id) {
            return response()->json([
                'message' => 'This IPCR covers a different review period.',
            ], 422);
        }

        $summary = $this->computeSummary($form, $period);

        if ($summary['rated_indicators'] < $summary['total_indicators']) {
            $missing = $summary['total_indicators'] - $summary['rated_indicators'];

            return response()->json([
                'message' => "{$missing} indicator(s) still have no rating. Rate every line before finalizing.",
            ], 422);
        }

        DB::transaction(function () use ($form, $period, $summary, $user) {
            PcrPeriodSummary::updateOrCreate(
                ['form_id' => $form->id, 'rating_period_id' => $period->id],
                [
                    'strategic_average' => $summary['strategic_average'],
                    'core_average'      => $summary['core_average'],
                    'support_average'   => $summary['support_average'],
                    'final_average'     => $summary['final_average'],
                    'adjectival'        => $summary['adjectival'],
                    'rated_indicators'  => $summary['rated_indicators'],
                    'total_indicators'  => $summary['total_indicators'],
                ]
            );

            $fromStatus = $form->status;

            $form->update([
                'status'        => 'rated',
                'rated_by'      => $user->id,
                'rated_by_name' => $user->name,
                'rated_at'      => now(),
            ]);

            PcrStatusLog::record(
                $form->id,
                $fromStatus,
                'rated',
                "{$period->label}: {$summary['final_average']} ({$summary['adjectival']})"
            );
        });

        ActivityLog::record(
            'PcrForm',
            $form->id,
            'rate',
            "Rated {$summary['final_average']} — {$summary['adjectival']}"
        );

        $form->loadMissing('orgUnit');

        Notification::sendMany(
            [$form->user_id, $form->orgUnit?->head_user_id, $form->orgUnit?->dean_user_id, $form->orgUnit?->vp_user_id],
            'rated',
            strtoupper($form->type) . ' rating is out',
            "{$period->label}: {$summary['final_average']} — {$summary['adjectival']}",
            $form->id
        );

        return response()->json(['data' => 'finalized', 'summary' => $summary, 'form' => $form->fresh()]);
    }

    /**
     * Commitments linked to a target. QA, and the other roles that read every
     * form, see the whole link. A head or VP sees only their own IPCR, the
     * lines hanging off it, and the targets they cascaded that they can already
     * read. Scoring stays with whoever's turn it is.
     */
    public function targets(Request $request)
    {
        $user = $request->user();
        $periodFilter = $request->query('rating_period_id');
        $sectionOrder = ['strategic' => 0, 'core' => 1, 'support' => 2];
        $seesAll = in_array($user->role, ['admin', 'qa', 'hr', 'president'], true)
            || PcrWorkflow::isRater($user);
        $limited = in_array($user->role, ['program_head', 'vp'], true) && ! $seesAll;
        $ownLineIds = [];
        $cascadedTargetIds = [];

        if ($limited) {
            $ownLineIds = PcrIndicator::query()
                ->whereHas('output.form', fn ($query) => $query
                    ->where('type', 'ipcr')
                    ->where('user_id', $user->id))
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all();

            $cascadedTargetIds = PcrTargetAssignment::query()
                ->where('assigned_by', $user->id)
                ->get(['indicator_id', 'user_id'])
                ->mapWithKeys(fn ($row) => [(int) $row->indicator_id . ':' . (int) $row->user_id => true])
                ->all();
        }

        $forms = PcrForm::query()
            ->with([
                'owner:id,name,org_unit_id',
                'orgUnit:id,name',
                'schoolYear:id,label',
                'schoolYear.periods:id,school_year_id,label,seq,is_active',
                'ratingPeriod:id,label',
                'stageReviewers',
                'outputs.indicators' => fn ($query) => $query->whereNotNull('parent_indicator_id'),
                'outputs.indicators.ratings',
                'outputs.indicators.accomplishments.attachments',
                'outputs.indicators.parent.output.form:id,type,user_id,org_unit_id',
                'outputs.indicators.parent.output.form.owner:id,role',
                'outputs.indicators.parent.output.form.orgUnit:id,name',
                'outputs.indicators.parent.output',
            ])
            ->whereHas('outputs.indicators', fn ($query) => $query->whereNotNull('parent_indicator_id'))
            ->get();

        $groups = [];

        foreach ($forms as $form) {
            if ($form->status === 'final') {
                continue;
            }

            if ($periodFilter && $form->type === 'ipcr' && (int) $form->rating_period_id !== (int) $periodFilter) {
                continue;
            }

            $canScore = PcrWorkflow::mayScore($user, $form);
            $canView  = PcrWorkflow::canView($user, $form);

            foreach ($form->outputs as $output) {
                foreach ($output->indicators as $line) {
                    $parent = $line->parent;

                    if (! $parent) {
                        continue;
                    }

                    $visible = ! $limited || $this->visibleToHeadOrVp(
                        $user,
                        $form,
                        (int) $parent->id,
                        $canView,
                        $ownLineIds,
                        $cascadedTargetIds
                    );

                    if (! $visible) {
                        continue;
                    }

                    $groups[$parent->id] ??= [
                        'id'          => $parent->id,
                        'description' => $parent->description,
                        'section'     => $parent->output?->section,
                        'output'      => $parent->output?->title,
                        'office'      => $parent->output?->form?->orgUnit?->name,
                        'kind'        => $this->targetKind($parent),
                        'open'        => false,
                        'lines'       => [],
                    ];

                    // A head or VP already passed the narrower check. Everyone
                    // else still opens a target by scoring it, or by being
                    // allowed to read a form they do not own.
                    if ($limited || $seesAll || $canScore || ($canView && ! PcrWorkflow::owns($user, $form))) {
                        $groups[$parent->id]['open'] = true;
                    }

                    $groups[$parent->id]['lines'][] = $this->targetLine($form, $output, $line, $canScore);
                }
            }
        }

        $groups = array_filter($groups, fn ($group) => $group['open']);
        $groups = array_map(function ($group) {
            unset($group['open']);

            return $group;
        }, $groups);

        $targets = collect($groups)
            ->map(function ($group) {
                $group['lines'] = collect($group['lines'])
                    ->sortBy(fn ($line) => [$line['form']['owner'] ?? $line['form']['org_unit'] ?? '', $line['id']])
                    ->values()
                    ->all();

                return $group;
            })
            ->sortBy(fn ($group) => [
                $sectionOrder[$group['section']] ?? 9,
                $group['id'],
            ])
            ->values();

        return response()->json($targets);
    }

    /**
     * @param  array<int, true>  $ownLineIds
     * @param  array<string, true>  $cascadedTargetIds
     */
    private function visibleToHeadOrVp(
        User $user,
        PcrForm $form,
        int $parentId,
        bool $canView,
        array $ownLineIds,
        array $cascadedTargetIds
    ): bool {
        if ($form->type === 'ipcr' && (int) $form->user_id === (int) $user->id) {
            return true;
        }

        if (isset($ownLineIds[$parentId])) {
            return true;
        }

        $ownerId = (int) $form->user_id;

        return $ownerId > 0
            && isset($cascadedTargetIds[$parentId . ':' . $ownerId])
            && $canView;
    }

    /** OPCR, or the role of the person whose IPCR line is the target. */
    private function targetKind($parent): string
    {
        $form = $parent->output?->form;

        if (! $form || $form->type === 'opcr') {
            return 'opcr';
        }

        return $form->owner?->role ?: 'employee';
    }

    private function targetLine(PcrForm $form, $output, $line, bool $canScore): array
    {
        return [
            'id'          => $line->id,
            'description' => $line->description,
            'section'     => $output->section,
            'output'      => $output->title,
            'can_score'   => $canScore,
            'form'        => [
                'id'               => $form->id,
                'type'             => $form->type,
                'status'           => $form->status,
                'owner'            => $form->owner?->name,
                'org_unit'         => $form->orgUnit?->name,
                'rating_period_id' => $form->rating_period_id,
                'school_year'      => $form->schoolYear?->label,
                'periods'          => $form->schoolYear?->periods
                    ?->sortBy('seq')
                    ->map(fn ($period) => [
                        'id'        => $period->id,
                        'label'     => $period->label,
                        'is_active' => (bool) $period->is_active,
                    ])
                    ->values(),
            ],
            'ratings' => $line->ratings->map(fn ($rating) => $rating->only([
                'rating_period_id', 'q', 'e', 't', 'a', 'remarks',
            ]))->values(),
            'accomplishments' => $line->accomplishments->map(fn ($row) => [
                'rating_period_id'      => $row->rating_period_id,
                'actual_accomplishment' => $row->actual_accomplishment,
                'attachments'           => $row->attachments->map(fn ($file) => ['id' => $file->id])->values(),
            ])->values(),
        ];
    }

    /** A commitment approver may write the remark before anyone has scored the line. */
    private function mayLeaveRemarks(User $user, PcrForm $form): bool
    {
        if ($form->type === 'ipcr' && $form->status === 'commitment_review') {
            return (int) $form->commitment_approver_id === (int) $user->id;
        }

        if ($form->type === 'opcr' && $form->status === 'qa_approval') {
            return in_array($user->role, app(WorkflowSettings::class)->opcr('approver_roles'), true);
        }

        return false;
    }

    private function indicatorIds(PcrForm $form): array
    {
        return PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function computeSummary(PcrForm $form, RatingPeriod $period): array
    {
        $rows = PcrIndicator::query()
            ->join('pcr_outputs', 'pcr_indicators.output_id', '=', 'pcr_outputs.id')
            ->leftJoin('pcr_ratings', function ($join) use ($period) {
                $join->on('pcr_ratings.indicator_id', '=', 'pcr_indicators.id')
                    ->where('pcr_ratings.rating_period_id', '=', $period->id);
            })
            ->where('pcr_outputs.form_id', $form->id)
            ->get(['pcr_indicators.id', 'pcr_outputs.section', 'pcr_ratings.a']);

        $bySection = ['strategic' => [], 'core' => [], 'support' => []];
        $rated     = 0;

        foreach ($rows as $row) {
            $value = $row->a === null ? null : (float) $row->a;

            if ($value !== null) {
                $rated++;
            }

            $bySection[$row->section][] = $value;
        }

        $sectionAverages = [
            'strategic' => RatingScale::mean($bySection['strategic']),
            'core'      => RatingScale::mean($bySection['core']),
            'support'   => RatingScale::mean($bySection['support']),
        ];

        $final = RatingScale::mean(array_values($sectionAverages));

        return [
            'strategic_average' => $sectionAverages['strategic'],
            'core_average'      => $sectionAverages['core'],
            'support_average'   => $sectionAverages['support'],
            'final_average'     => $final,
            'adjectival'        => RatingScale::adjectival($final),
            'rated_indicators'  => $rated,
            'total_indicators'  => $rows->count(),
        ];
    }
}
