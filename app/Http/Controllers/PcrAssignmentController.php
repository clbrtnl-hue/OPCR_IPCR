<?php

namespace App\Http\Controllers;

use App\Models\Notification;
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

    /** Everyone who may be made accountable — the whole organization bar system accounts. */
    public function assignableUsers()
    {
        return response()->json(
            User::whereNotIn('role', app(WorkflowSettings::class)->get('delegation')['assignable_excludes_roles'] ?? ['admin'])
                ->where('status', 'active')
                ->orderByPerson()
                ->get(['id', 'name', 'position_title', 'role', 'org_unit_id'])
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
            $periodId = RatingPeriod::where('school_year_id', $parent->form->school_year_id)
                ->orderByDesc('is_active')
                ->orderBy('seq')
                ->value('id');
        }

        $created = 0;

        foreach ($this->assigneesFrom($data, $parent->form) as $assignee) {
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

        $created = 0;

        foreach ($this->assigneesFrom($data, $form) as $assignee) {
            $already = PcrTargetAssignment::where('indicator_id', $parent->id)
                ->where('user_id', $assignee->id)
                ->exists();

            if ($already) {
                continue;
            }

            $this->assignments->assignIndicator($parent, $assignee, $actor);
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
            $periodId = $form->rating_period_id ?: RatingPeriod::where('school_year_id', $form->school_year_id)
                ->orderByDesc('is_active')
                ->orderBy('seq')
                ->value('id');
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

        $assignees = $this->assigneesFrom($data, $form);

        if ($assignees->isEmpty()) {
            return response()->json([
                'message' => 'None of those people can be made accountable for this form.',
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
        if (in_array($actor->role, WorkflowSettings::REVIEW_ONLY_ROLES, true)) {
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

    private function assigneesFrom(array $data, PcrForm $form)
    {
        return User::whereIn('id', $data['user_ids'])
            ->where('role', '!=', 'admin')
            ->where('status', 'active')
            ->get()
            ->reject(fn ($u) => (int) $u->id === (int) $form->user_id);
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

        if (! $this->assignments->withdrawAssignment($assignment)) {
            return response()->json([
                'message' => 'This has been worked on already. Ask the assignee to close it out instead of withdrawing it.',
            ], 409);
        }

        return response()->json(['data' => 'withdrawn']);
    }
}
