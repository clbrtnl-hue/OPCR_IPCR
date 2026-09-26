<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrTargetAssignment;
use App\Models\RatingPeriod;
use App\Models\User;
use App\Services\PcrAssignmentService;
use App\Services\WorkflowSettings;
use App\Services\PcrWorkflow;
use Illuminate\Http\Request;

class PcrAssignmentController extends Controller
{
    public function __construct(private PcrAssignmentService $assignments)
    {
    }

    /** People this account may name. A head, VP, or QA only sees their own team. */
    public function assignableUsers(Request $request)
    {
        $actor = $request->user();

        $query = User::whereNotIn('role', app(WorkflowSettings::class)->get('delegation')['assignable_excludes_roles'] ?? ['admin'])
            ->where('status', 'active');

        $team = $this->teamUnitIds($actor);

        if ($team !== null) {
            $query->whereIn('org_unit_id', $team);
        }

        return response()->json(
            $query->orderByPerson()->get(['id', 'name', 'position_title', 'role', 'org_unit_id'])
        );
    }

    public function storeForOutput(Request $request, $id)
    {
        $data   = $this->validateAssignees($request);
        $parent = PcrOutput::with('form')->findOrFail($id);
        $actor  = $request->user();

        if ($problem = $this->mayDelegate($actor, $parent->form, 'output')) {
            return $problem;
        }

        $periodId = $request->input('rating_period_id');

        if ($periodId) {
            $belongs = RatingPeriod::where('id', $periodId)
                ->where('school_year_id', $parent->form->school_year_id)
                ->exists();

            if (! $belongs) {
                return response()->json([
                    'message' => 'That review period belongs to a different school year.',
                ], 422);
            }
        } else {
            $periodId = PcrWorkflow::periodForWrite($parent->form);
        }

        if ($message = PcrWorkflow::lockMessage($actor, $periodId ? (int) $periodId : null)) {
            return response()->json(['message' => $message], 409);
        }

        $assignees = $this->assigneesFrom($data, $parent->form, $actor);

        if ($assignees->isEmpty()) {
            return response()->json([
                'message' => 'None of those people are on your team.',
            ], 422);
        }

        $created = 0;

        foreach ($assignees as $assignee) {
            $already = PcrOutput::where('parent_output_id', $parent->id)
                ->whereHas('form', fn ($q) => $q->where('user_id', $assignee->id)
                    ->where('rating_period_id', $periodId))
                ->exists();

            if ($already) {
                continue;
            }

            $this->assignments->assignOutput($parent, $assignee, $actor, $periodId ? (int) $periodId : null);
            $created++;
        }

        return response()->json(['data' => 'assigned', 'assigned' => $created], 201);
    }

    public function storeForIndicator(Request $request, $id)
    {
        $data   = $this->validateAssignees($request);
        $parent = PcrIndicator::with('output.form')->findOrFail($id);
        $actor  = $request->user();
        $form   = $parent->output->form;

        if ($problem = $this->mayDelegate($actor, $form, 'indicator')) {
            return $problem;
        }

        $periodId = $request->input('rating_period_id')
            ?: $parent->rating_period_id
            ?: PcrWorkflow::periodForWrite($form);

        if ($request->filled('rating_period_id') && ! RatingPeriod::where('id', $periodId)->where('school_year_id', $form->school_year_id)->exists()) {
            return response()->json([
                'message' => 'That review period belongs to a different school year.',
            ], 422);
        }

        if ($message = PcrWorkflow::lockMessage($actor, $periodId ? (int) $periodId : null)) {
            return response()->json(['message' => $message], 409);
        }

        $assignees = $this->assigneesFrom($data, $form, $actor);

        if ($assignees->isEmpty()) {
            return response()->json([
                'message' => 'None of those people are on your team.',
            ], 422);
        }

        $created = 0;

        foreach ($assignees as $assignee) {
            $already = PcrTargetAssignment::where('indicator_id', $parent->id)
                ->where('user_id', $assignee->id)
                ->exists();

            if ($already) {
                continue;
            }

            $this->assignments->assignIndicator($parent, $assignee, $actor, $periodId ? (int) $periodId : null);
            $created++;
        }

        return response()->json(['data' => 'assigned', 'assigned' => $created], 201);
    }

    public function cascade(Request $request, $id)
    {
        $data = $request->validate([
            'user_ids'         => 'required|array|min:1',
            'user_ids.*'       => 'integer|exists:users,id',
            'indicator_ids'    => 'nullable|array',
            'indicator_ids.*'  => 'integer',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $form  = PcrForm::findOrFail($id);
        $actor = $request->user();

        if ($problem = $this->mayDelegate($actor, $form, 'indicator')) {
            return $problem;
        }

        $periodId = $data['rating_period_id'] ?? null;

        if ($periodId) {
            $belongs = RatingPeriod::where('id', $periodId)
                ->where('school_year_id', $form->school_year_id)
                ->exists();

            if (! $belongs) {
                return response()->json([
                    'message' => 'That review period belongs to a different school year.',
                ], 422);
            }
        } else {
            $periodId = PcrWorkflow::periodForWrite($form);
        }

        if ($message = PcrWorkflow::lockMessage($actor, $periodId ? (int) $periodId : null)) {
            return response()->json(['message' => $message], 409);
        }

        $lines = PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))
            ->when(
                ! empty($data['indicator_ids']),
                fn ($q) => $q->whereIn('id', $data['indicator_ids'])
            )
            ->with('output.form')
            ->orderBy('output_id')
            ->orderBy('sort_order')
            ->get();

        if (! empty($data['indicator_ids']) && $lines->count() !== count(array_unique($data['indicator_ids']))) {
            return response()->json([
                'message' => 'Some of those lines do not belong to this form.',
            ], 422);
        }

        if ($lines->isEmpty()) {
            return response()->json([
                'message' => 'There is nothing on this form to hand out yet.',
            ], 409);
        }

        $assignees = $this->assigneesFrom($data, $form, $actor);

        if ($assignees->isEmpty()) {
            return response()->json([
                'message' => 'None of those people are on your team.',
            ], 422);
        }

        $assigned = 0;
        $skipped  = 0;
        $touched  = [];

        foreach ($lines as $line) {
            foreach ($assignees as $assignee) {
                $already = PcrTargetAssignment::where('indicator_id', $line->id)
                    ->where('user_id', $assignee->id)
                    ->where('rating_period_id', $periodId)
                    ->exists();

                if ($already) {
                    $skipped++;

                    continue;
                }

                $this->assignments->assignIndicator(
                    $line,
                    $assignee,
                    $actor,
                    $periodId ? (int) $periodId : null,
                    false
                );

                $assigned++;
                $touched[$assignee->id] = ($touched[$assignee->id] ?? 0) + 1;
            }
        }

        foreach ($touched as $userId => $count) {
            Notification::send(
                $userId,
                'assignment',
                "{$actor->name} assigned you " . $count . ' ' . ($count === 1 ? 'commitment' : 'commitments'),
                'They are waiting in your IPCR. Write your own commitments against each assigned target.',
                $form->id
            );
        }

        return response()->json([
            'data'     => 'cascaded',
            'assigned' => $assigned,
            'skipped'  => $skipped,
            'lines'    => $lines->count(),
            'people'   => $assignees->count(),
        ], 201);
    }

    private function validateAssignees(Request $request): array
    {
        return $request->validate([
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ]);
    }

    /**
     * Faculty are the bottom of the chain — the work stops being divided at the
     * person who actually does it.
     */
    private function mayDelegate(User $actor, PcrForm $form, string $level)
    {
        if ($level !== 'indicator' && in_array($actor->role, WorkflowSettings::REVIEW_ONLY_ROLES, true)) {
            return $this->reviewOnly();
        }

        if (! PcrWorkflow::owns($actor, $form)) {
            return response()->json([
                'message' => 'Only the owner of this commitment can hand parts of it to someone else.',
            ], 403);
        }

        if ($actor->isAdmin()) {
            return null;
        }

        $rules = app(WorkflowSettings::class);

        if ($rules->isTerminalRole($actor->role)) {
            return response()->json([
                'message' => 'Your commitments are yours to deliver — they cannot be passed on again.',
            ], 403);
        }

        $allowed = $level === 'output'
            ? $rules->mayAssignOutputs($actor->role)
            : $rules->mayAssignIndicators($actor->role);

        if (! $allowed) {
            return response()->json([
                'message' => 'Your role does not hand out this kind of commitment.',
            ], 403);
        }

        return null;
    }

    private function reviewOnly()
    {
        return response()->json([
            'message' => 'A VP reviews commitments; work is not handed out from a VP account.',
        ], 403);
    }

    private function assigneesFrom(array $data, PcrForm $form, ?User $actor = null)
    {
        $team = $this->teamUnitIds($actor);

        return User::whereIn('id', $data['user_ids'])
            ->where('role', '!=', 'admin')
            ->where('status', 'active')
            ->when($team !== null, fn ($query) => $query->whereIn('org_unit_id', $team))
            ->get()
            ->reject(fn ($u) => (int) $u->id === (int) $form->user_id);
    }

    /**
     * Null means the whole organization. A head, VP, or QA is limited to the
     * offices they lead and the one they sit in, including every unit under those.
     */
    private function teamUnitIds(?User $actor): ?array
    {
        if (! $actor || ! in_array($actor->role, ['program_head', 'vp', 'qa'], true)) {
            return null;
        }

        $roots = OrgUnit::overseenIds((int) $actor->id);

        if ($actor->org_unit_id) {
            $roots = array_values(array_unique(array_merge(
                $roots,
                OrgUnit::subtreeIds([(int) $actor->org_unit_id])
            )));
        }

        return $roots;
    }

    public function destroy(Request $request, $assignmentId)
    {
        $assignment = PcrTargetAssignment::with('indicator.output.form')->find($assignmentId);

        if ($assignment) {
            return $this->destroyAssignment($request, $assignment);
        }

        // Legacy: withdrawing used the child IPCR line's id.
        $child  = PcrIndicator::with('output.form')->findOrFail($assignmentId);
        $parent = $child->parent;

        if (! $parent || ! $child->assigned_by) {
            return response()->json(['message' => 'That line was not assigned by anyone.'], 409);
        }

        $actor = $request->user();

        if (in_array($actor->role, WorkflowSettings::REVIEW_ONLY_ROLES, true)) {
            return $this->reviewOnly();
        }

        if (! PcrWorkflow::owns($actor, $parent->output->form)) {
            return response()->json([
                'message' => 'Only the person who handed this out can take it back.',
            ], 403);
        }

        if ($message = PcrWorkflow::lockMessage(
            $actor,
            PcrWorkflow::periodForWrite($parent->output->form, $child->rating_period_id)
        )) {
            return response()->json(['message' => $message], 409);
        }

        if (! $this->assignments->withdraw($child)) {
            return response()->json([
                'message' => 'This has been worked on already. Ask the assignee to close it out instead of withdrawing it.',
            ], 409);
        }

        return response()->json(['data' => 'withdrawn']);
    }

    private function destroyAssignment(Request $request, PcrTargetAssignment $assignment)
    {
        $actor = $request->user();

        if (in_array($actor->role, WorkflowSettings::REVIEW_ONLY_ROLES, true)) {
            return $this->reviewOnly();
        }

        if (! PcrWorkflow::owns($actor, $assignment->indicator->output->form)) {
            return response()->json([
                'message' => 'Only the person who handed this out can take it back.',
            ], 403);
        }

        if ($message = PcrWorkflow::lockMessage(
            $actor,
            PcrWorkflow::periodForWrite($assignment->indicator->output->form, $assignment->rating_period_id)
        )) {
            return response()->json(['message' => $message], 409);
        }

        if (! $this->assignments->withdrawAssignment($assignment)) {
            return response()->json([
                'message' => 'This has been worked on already. Ask the assignee to close it out instead of withdrawing it.',
            ], 409);
        }

        return response()->json(['data' => 'withdrawn']);
    }
}
