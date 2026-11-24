<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
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
            // A new line may be blank. The sheet shows a placeholder until it
            // is written, and readiness still blocks an empty target.
            'description'       => ['nullable', 'string', Html::maxChars(500)],
            'target_date'       => 'nullable|date',
            'allotted_budget'   => 'nullable|numeric|min:0',
            'accountable'       => 'nullable|string|max:255',
            'parent_indicator_id' => 'nullable|integer|exists:pcr_indicators,id',
            'rating_period_id'  => 'nullable|integer|exists:rating_periods,id',
            'sort_order'        => 'nullable|integer|min:0',
        ]);

        $output = PcrOutput::with('form')->findOrFail($data['output_id']);
        $existingLine = $id ? PcrIndicator::find($id) : null;

        if ($existingLine?->closed_at) {
            return response()->json([
                'message' => 'This commitment was closed when its target was replaced.',
            ], 409);
        }

        // A restarted line stays editable after the rest of the form is approved.
        $restartWrite = $existingLine
            && $existingLine->needs_commitment_approval
            && ! $existingLine->line_approved_at
            && ! $existingLine->line_submitted_at
            && PcrWorkflow::owns($request->user(), $output->form)
            && ! in_array($output->form->status, ['rated', 'final', 'appeal'], true);

        if (! $restartWrite && ! PcrWorkflow::canEditCommitments($request->user(), $output->form)) {
            return response()->json([
                'message' => PcrWorkflow::commitmentClosedMessage($output->form)
                    ?? 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        $isOpcr = $output->form->type === 'opcr';

        // An OPCR line sits at the top of the tree; it never hangs off anything.
        // A restarted commitment keeps the replacement it was given.
        $parentId = $restartWrite
            ? $existingLine->parent_indicator_id
            : ($isOpcr ? null : ($data['parent_indicator_id'] ?? null));

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

        if ($message = PcrWorkflow::formLockMessage($request->user(), $output->form, $periodId)) {
            return response()->json(['message' => $message], 409);
        }

        if ($parentId && ! $restartWrite) {
            if ($error = $this->parentProblem($parentId, $output, $periodId, $id)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $existing     = $id ? PcrIndicator::find($id) : null;
        $parent       = $parentId ? PcrIndicator::with('output.form')->find($parentId) : null;

        // A field left out of the request keeps what it had. A field that is
        // sent, even blank, is the new text — putting the previous sentence
        // back is what made a copied line impossible to rewrite.
        $kept = fn (string $field) => array_key_exists($field, $data)
            ? $data[$field]
            : $existing?->{$field};

        $description = array_key_exists('description', $data)
            ? (Html::clean($data['description'] ?? null) ?? '')
            : $existing?->description;

        if (blank($description)) {
            $description = '';
        }

        $attributes = [
            'output_id'         => $output->id,
            'description'       => $description,
            'target_date'       => $kept('target_date'),
            'allotted_budget'   => $isOpcr ? $kept('allotted_budget') : null,
            'accountable'       => $isOpcr ? $kept('accountable') : null,
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

        if ($message = PcrWorkflow::formLockMessage($user, $form, $indicator->rating_period_id)) {
            return response()->json(['message' => $message], 409);
        }

        if ($indicator->children()->exists() || $indicator->assignments()->exists()) {
            return response()->json([
                'message' => 'This line is rolled up from the commitments written against it. Update those instead.',
            ], 409);
        }

        return response()->json([
            'message' => 'Progress follows the accomplishment. The narrative counts as 30% and two files count as 70% (35% each). Both together reach 100%.',
        ], 409);
    }

    public function destroy(Request $request, $id)
    {
        $indicator = PcrIndicator::with('output.form')->findOrFail($id);

        if ($indicator->closed_at) {
            return response()->json([
                'message' => 'This commitment was closed when its target was replaced.',
            ], 409);
        }

        if ($indicator->needs_commitment_approval && ! $indicator->line_approved_at) {
            return response()->json([
                'message' => 'Write the new commitment on this line. It replaced the one above it.',
            ], 409);
        }

        if (! PcrWorkflow::canEditCommitments($request->user(), $indicator->output->form)) {
            return response()->json([
                'message' => PcrWorkflow::commitmentClosedMessage($indicator->output->form)
                    ?? 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        if ($message = PcrWorkflow::formLockMessage(
            $request->user(),
            $indicator->output->form,
            $indicator->rating_period_id
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

    /** The owner sends only the restarted line. The rest of the form stays where it is. */
    public function submitRestart(Request $request, $id)
    {
        $indicator = PcrIndicator::with('output.form')->findOrFail($id);
        $form = $indicator->output->form;
        $user = $request->user();

        if (! PcrWorkflow::owns($user, $form)) {
            return response()->json(['message' => 'Only the owner of this form can send this commitment.'], 403);
        }

        if ($message = $this->restartBlock($indicator, $form, $user)) {
            return response()->json(['message' => $message], 409);
        }

        if (Html::isBlank($indicator->description)) {
            return response()->json(['message' => 'Write the new commitment before sending it.'], 422);
        }

        if (! $indicator->line_approver_id) {
            return response()->json(['message' => 'Nobody is set to approve this commitment.'], 409);
        }

        $indicator->update(['line_submitted_at' => now()]);

        Notification::send(
            $indicator->line_approver_id,
            'approval',
            'A replaced commitment needs your approval',
            'One line was started over after its target changed. The rest of the form is unchanged.',
            $form->id,
            "/forms/{$form->id}"
        );

        return response()->json(['data' => 'submitted']);
    }

    public function approveRestart(Request $request, $id)
    {
        $indicator = PcrIndicator::with('output.form')->findOrFail($id);
        $user = $request->user();

        if ((int) $indicator->line_approver_id !== (int) $user->id) {
            return response()->json(['message' => 'This commitment is waiting for somebody else.'], 403);
        }

        if (! $indicator->needs_commitment_approval || $indicator->line_approved_at || ! $indicator->line_submitted_at) {
            return response()->json(['message' => 'This commitment is not waiting for approval.'], 409);
        }

        $indicator->update([
            'line_approved_at'          => now(),
            'needs_commitment_approval' => false,
        ]);

        $this->logReplacedCommitment($indicator, $user);

        Notification::send(
            $indicator->output->form->user_id,
            'approved',
            'Your new commitment was approved',
            'The line that replaced the old target is now part of your IPCR.',
            $indicator->output->form_id,
            "/forms/{$indicator->output->form_id}"
        );

        return response()->json(['data' => 'approved']);
    }

    public function returnRestart(Request $request, $id)
    {
        $data = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        $indicator = PcrIndicator::with('output.form')->findOrFail($id);
        $user = $request->user();

        if ((int) $indicator->line_approver_id !== (int) $user->id) {
            return response()->json(['message' => 'This commitment is waiting for somebody else.'], 403);
        }

        if (! $indicator->line_submitted_at || $indicator->line_approved_at) {
            return response()->json(['message' => 'This commitment is not waiting for approval.'], 409);
        }

        $note = Html::clean($data['note']);

        if (Html::isBlank($note)) {
            return response()->json(['message' => 'Say what needs rewriting before sending it back.'], 422);
        }

        $indicator->update(['line_submitted_at' => null]);

        Notification::send(
            $indicator->output->form->user_id,
            'returned',
            'Your new commitment was sent back',
            Html::toText($note),
            $indicator->output->form_id,
            "/forms/{$indicator->output->form_id}"
        );

        return response()->json(['data' => 'returned']);
    }

    /** Every restarted line on this form goes together. One notice. */
    public function submitRestarts(Request $request, $id)
    {
        $form = PcrForm::findOrFail($id);
        $user = $request->user();

        if (! PcrWorkflow::owns($user, $form)) {
            return response()->json(['message' => 'Only the owner of this form can send these commitments.'], 403);
        }

        $lines = $this->openRestarts($form)->whereNull('line_submitted_at')->values();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'No new commitments are waiting to be sent.'], 409);
        }

        foreach ($lines as $indicator) {
            if ($message = $this->restartBlock($indicator, $form, $user)) {
                return response()->json(['message' => $message], 409);
            }

            if (Html::isBlank($indicator->description)) {
                return response()->json(['message' => 'Write every new commitment before sending them.'], 422);
            }

            if (! $indicator->line_approver_id) {
                return response()->json(['message' => 'Nobody is set to approve this commitment.'], 409);
            }
        }

        foreach ($lines as $indicator) {
            $indicator->update(['line_submitted_at' => now()]);
        }

        $count = $lines->count();

        foreach ($lines->pluck('line_approver_id')->unique() as $approverId) {
            Notification::send(
                $approverId,
                'approval',
                $count === 1
                    ? 'A replaced commitment needs your approval'
                    : "{$count} replaced commitments need your approval",
                'These lines were started over after a target changed. The rest of the form is unchanged.',
                $form->id,
                "/forms/{$form->id}"
            );
        }

        return response()->json(['data' => 'submitted', 'count' => $count]);
    }

    public function decideRestarts(Request $request, $id)
    {
        $form = PcrForm::findOrFail($id);
        $user = $request->user();
        $approve = $request->boolean('approve');
        $note = null;

        if (! $approve) {
            $data = $request->validate([
                'note' => 'required|string|max:2000',
            ]);
            $note = Html::clean($data['note']);

            if (Html::isBlank($note)) {
                return response()->json(['message' => 'Say what needs rewriting before sending it back.'], 422);
            }
        }

        $lines = $this->openRestarts($form)
            ->where('line_approver_id', $user->id)
            ->whereNotNull('line_submitted_at')
            ->values();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'No new commitments are waiting for you.'], 409);
        }

        foreach ($lines as $indicator) {
            $indicator->update($approve
                ? ['line_approved_at' => now(), 'needs_commitment_approval' => false]
                : ['line_submitted_at' => null]);

            if ($approve) {
                $this->logReplacedCommitment($indicator, $user);
            }
        }

        $count = $lines->count();

        Notification::send(
            $form->user_id,
            $approve ? 'approved' : 'returned',
            $approve
                ? ($count === 1 ? 'Your new commitment was approved' : "{$count} new commitments were approved")
                : ($count === 1 ? 'Your new commitment was sent back' : "{$count} new commitments were sent back"),
            $approve
                ? 'The lines that replaced the old targets are now part of your IPCR.'
                : Html::toText($note),
            $form->id,
            "/forms/{$form->id}"
        );

        return response()->json(['data' => $approve ? 'approved' : 'returned', 'count' => $count]);
    }

    private function logReplacedCommitment(PcrIndicator $line, $actor): void
    {
        if (! $line->replaces_indicator_id) {
            return;
        }

        $old = PcrIndicator::find($line->replaces_indicator_id);
        $formId = PcrOutput::whereKey($line->output_id)->value('form_id');

        if (! $old || ! $formId) {
            return;
        }

        $plain = fn (?string $text) => ($text = trim(Html::toText($text ?? ''))) === '' ? 'None' : $text;

        ActivityLog::record(
            'PcrForm',
            (int) $formId,
            'replace',
            'These cells changed.',
            [[
                'field' => 'Success indicator',
                'from'  => $plain($old->description),
                'to'    => $plain($line->description),
            ]],
            $actor
        );
    }

    private function openRestarts(PcrForm $form)
    {
        return PcrIndicator::query()
            ->whereHas('output', fn ($query) => $query->where('form_id', $form->id))
            ->where('needs_commitment_approval', true)
            ->whereNull('closed_at')
            ->whereNull('line_approved_at')
            ->get();
    }

    private function restartBlock(PcrIndicator $indicator, $form, $user): ?string
    {
        if ($indicator->closed_at || ! $indicator->needs_commitment_approval || $indicator->line_approved_at) {
            return 'This line is not a commitment started over after a target change.';
        }

        if ($indicator->line_submitted_at) {
            return 'This commitment is already waiting for approval.';
        }

        if (in_array($form->status, ['rated', 'final', 'appeal'], true)) {
            return 'This form has been rated and can no longer be changed.';
        }

        return PcrWorkflow::formLockMessage($user, $form, $indicator->rating_period_id);
    }

    /**
     * Whether this line may hang off that one. A commitment cascades: an office
     * target is delivered by a head, whose line is delivered by faculty, and so
     * on — so a parent is either the unit's published OPCR target or another
     * IPCR line in the same cycle. An office target lasts the school year, so
     * either review period may answer it. A line handed on from somebody's IPCR
     * stays in that period, and a line may never end up inside its own subtree.
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

        $officeTarget = $parentForm->type === 'opcr';

        if (
            ! $officeTarget
            && $periodId
            && $parent->rating_period_id
            && (int) $parent->rating_period_id !== (int) $periodId
        ) {
            return 'Pick a target from the same review period as this line.';
        }

        if ($parentForm->type === 'opcr' && $form->picksAssignedTargetsOnly()) {
            $handedToMe = $form->user_id && PcrTargetAssignment::where('indicator_id', $parentId)
                ->where('user_id', $form->user_id)
                ->exists();

            if (! $handedToMe) {
                return 'Pick a target that was assigned to you.';
            }
        }

        // A head or VP may answer a published college target. Faculty and staff
        // only answer the line a head or VP assigned to them.
        if ($parent->closed_at || $parent->replacement_status === 'pending') {
            return 'That target has been replaced. Pick the current one.';
        }

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
