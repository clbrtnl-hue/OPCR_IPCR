<?php

namespace App\Http\Controllers;

use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrTargetAssignment;
use App\Support\Html;
use App\Models\RatingPeriod;
use App\Services\IndicatorProgressService;
use App\Services\PcrWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PcrIndicatorController extends Controller
{
    public function store(Request $request)
    {
        $id = $request->input('id');

        $data = $request->validate([
            'id'                => 'nullable|integer|exists:pcr_indicators,id',
            'output_id'         => 'required|integer|exists:pcr_outputs,id',
            'description'       => ['required_without:parent_indicator_id', 'nullable', 'string', Html::maxChars(500)],
            'target_date'       => 'nullable|date',
            'allotted_budget'   => 'nullable|numeric|min:0',
            'accountable'       => 'nullable|string|max:255',
            'parent_indicator_id' => 'nullable|integer|exists:pcr_indicators,id',
            'rating_period_id'  => 'nullable|integer|exists:rating_periods,id',
            'sort_order'        => 'nullable|integer|min:0',
        ]);

        $output = PcrOutput::with('form')->findOrFail($data['output_id']);

        if (! PcrWorkflow::canEditCommitments($request->user(), $output->form)) {
            return response()->json([
                'message' => 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        $isOpcr = $output->form->type === 'opcr';

        // An OPCR line sits at the top of the tree; it never hangs off anything.
        $parentId = $isOpcr ? null : ($data['parent_indicator_id'] ?? null);

        // A period-pinned IPCR decides the period for every line it holds; the
        // OPCR (and legacy year-wide IPCRs) still choose per line.
        $formPeriodId = ! $isOpcr ? $output->form->rating_period_id : null;

        $periodId = $isOpcr
            ? null
            : ($data['rating_period_id'] ?? ($id ? PcrIndicator::find($id)?->rating_period_id : null));

        if ($formPeriodId) {
            if ($periodId && (int) $periodId !== (int) $formPeriodId) {
                return response()->json([
                    'message' => 'This IPCR covers a single review period; its lines cannot be filed under another one.',
                ], 422);
            }

            $periodId = $formPeriodId;
        }

        if ($periodId) {
            $period = RatingPeriod::find($periodId);

            if (! $period || (int) $period->school_year_id !== (int) $output->form->school_year_id) {
                return response()->json([
                    'message' => 'That review period belongs to a different school year.',
                ], 422);
            }
        }

        if ($message = PcrWorkflow::lockMessage(
            $request->user(),
            PcrWorkflow::periodForWrite($output->form, $periodId)
        )) {
            return response()->json(['message' => $message], 409);
        }

        if ($parentId) {
            if ($error = $this->parentProblem($parentId, $output, $periodId, $id)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $existing     = $id ? PcrIndicator::find($id) : null;
        $parent       = $parentId ? PcrIndicator::with('output.form')->find($parentId) : null;

        if (blank($data['description'] ?? null) && blank($existing?->description)) {
            return response()->json(['message' => 'Describe the target and how it is measured.'], 422);
        }

        $attributes = [
            'output_id'         => $output->id,
            'description'       => filled($data['description'] ?? null)
                ? Html::clean($data['description'])
                : $existing->description,
            'target_date'       => $data['target_date'] ?? $existing?->target_date,
            'allotted_budget'   => $isOpcr ? ($data['allotted_budget'] ?? $existing?->allotted_budget) : null,
            'accountable'       => $isOpcr ? ($data['accountable'] ?? $existing?->accountable) : null,
            'parent_indicator_id' => $parentId,
            'rating_period_id'  => $periodId,
            // An edit keeps its place — recomputing here silently moved a row
            // to the bottom every time one of its cells was touched.
            'sort_order'        => $data['sort_order']
                ?? ($id
                    ? PcrIndicator::where('id', $id)->value('sort_order')
                    : PcrIndicator::where('output_id', $output->id)->max('sort_order') + 1),
        ];

        $progress = app(IndicatorProgressService::class);

        if ($id) {
            $indicator    = PcrIndicator::findOrFail($id);
            $formerParent = $indicator->parent;
            $indicator->update($attributes);

            if ((int) $formerParent?->id !== (int) $parentId) {
                if ($parentId) {
                    $progress->recalculateFrom($indicator->fresh());
                }

                if ($formerParent) {
                    $progress->releaseParent($formerParent);
                }
            }

            return response()->json(['data' => 'updated', 'indicator' => $indicator->fresh()]);
        }

        $indicator = PcrIndicator::create($attributes);

        if ($parentId) {
            $progress->recalculateFrom($indicator);
        }

        $progress->shareAcrossAssignedTargets($output->form);

        return response()->json(['data' => 'created', 'indicator' => $indicator], 201);
    }

    public function progress(Request $request, $id)
    {
        $data = $request->validate([
            'progress_status' => ['required', Rule::in(PcrIndicator::PROGRESS_STATUSES)],
            'progress_pct'    => 'nullable|integer|min:0|max:100',
        ]);

        $indicator = PcrIndicator::with('output.form')->findOrFail($id);
        $form      = $indicator->output->form;
        $user      = $request->user();

        if (! PcrWorkflow::owns($user, $form)) {
            return response()->json(['message' => 'Only the owner of this form can update its progress.'], 403);
        }

        if (in_array($form->status, ['rated', 'final'], true)) {
            return response()->json(['message' => 'This form has been rated and can no longer be changed.'], 409);
        }

        if ($message = PcrWorkflow::lockMessage(
            $user,
            PcrWorkflow::periodForWrite($form, $indicator->rating_period_id)
        )) {
            return response()->json(['message' => $message], 409);
        }

        if ($indicator->children()->exists() || $indicator->assignments()->exists()) {
            return response()->json([
                'message' => 'This line is rolled up from the commitments written against it. Update those instead.',
            ], 409);
        }

        return response()->json([
            'message' => 'Progress follows the accomplishment. A line reaches 100% when the actual accomplishment is written and a file is attached.',
        ], 409);
    }

    public function destroy(Request $request, $id)
    {
        $indicator = PcrIndicator::with('output.form')->findOrFail($id);

        if (! PcrWorkflow::canEditCommitments($request->user(), $indicator->output->form)) {
            return response()->json([
                'message' => 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        if ($message = PcrWorkflow::lockMessage(
            $request->user(),
            PcrWorkflow::periodForWrite($indicator->output->form, $indicator->rating_period_id)
        )) {
            return response()->json(['message' => $message], 409);
        }

        $parent = $indicator->parent;
        $form   = $indicator->output->form;
        $indicator->delete();

        $progress = app(IndicatorProgressService::class);

        if ($parent) {
            $progress->releaseParent($parent);
        }

        $progress->shareAcrossAssignedTargets($form);

        return response()->json(['data' => 'deleted']);
    }

    /**
     * Whether this line may hang off that one. A commitment cascades: an office
     * target is delivered by a head, whose line is delivered by faculty, and so
     * on — so a parent is either the unit's published OPCR target or another
     * IPCR line in the same cycle. Both must sit in the same review period, and
     * a line may never end up inside its own subtree.
     */
    private function parentProblem(int $parentId, PcrOutput $output, ?int $periodId, $selfId): ?string
    {
        $form       = $output->form;
        $parent     = PcrIndicator::with('output.form')->find($parentId);
        $parentForm = $parent?->output?->form;

        if (! $parentForm) {
            return 'That target no longer exists.';
        }

        if ((int) $parentForm->school_year_id !== (int) $form->school_year_id) {
            return 'Pick a target from the same school year.';
        }

        if ($periodId && $parent->rating_period_id && (int) $parent->rating_period_id !== (int) $periodId) {
            return 'Pick a target from the same review period as this line.';
        }

        if ($parentForm->type === 'opcr' && $form->picksAssignedTargetsOnly()) {
            return 'Pick a target that was assigned to you.';
        }

        // A head or VP may answer a published college target. Faculty and staff
        // only answer the line a head or VP assigned to them.
        if ($parentForm->type === 'opcr') {
            if (! PcrWorkflow::opcrIsVisible($parentForm)) {
                return 'The college OPCR has not been published yet, so its targets cannot be committed to.';
            }

            if ($output->section === 'support') {
                return 'Support Functions are your own; they are not picked from the college OPCR.';
            }

            if (
                $output->parent_output_id
                && $output->assigned_by
                && (int) $parent->output_id !== (int) $output->parent_output_id
            ) {
                return "Pick a success indicator the college set under “{$output->title}”.";
            }

            return null;
        }

        if ($selfId && $this->wouldCycle((int) $selfId, $parentId)) {
            return 'A line cannot be delivered by one of its own sub-tasks.';
        }

        $handedToMe = $form->user_id && PcrTargetAssignment::where('indicator_id', $parentId)
            ->where('user_id', $form->user_id)
            ->exists();

        if ($handedToMe) {
            return null;
        }

        return 'Pick a target from the college OPCR — sub-tasks under someone else’s line are created by assigning them.';
    }

    /** Walk up from the proposed parent; meeting ourselves means a loop. */
    private function wouldCycle(int $selfId, int $parentId): bool
    {
        $seen    = [];
        $current = $parentId;

        while ($current) {
            if ($current === $selfId || isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $current        = (int) (PcrIndicator::where('id', $current)->value('parent_indicator_id') ?? 0);
        }

        return false;
    }
}
