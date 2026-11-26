<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrTargetAssignment;
use App\Models\PcrTargetReplacement;
use App\Models\RatingPeriod;
use App\Models\User;
use App\Support\Html;
use Illuminate\Support\Facades\DB;

/**
 * A published OPCR target can be replaced without taking the form offline.
 * QA approves the new wording. Commitments already linked to the old target
 * are closed and their owners start a new one against the replacement.
 */
class TargetReplacementService
{
    public function propose(User $actor, PcrIndicator $old, array $changes, bool $notify = true): PcrTargetReplacement
    {
        $old->loadMissing('output.form');
        $form = $old->output?->form;

        if (! $form || $form->type !== 'opcr') {
            throw new \RuntimeException('Only a college OPCR target can be replaced.');
        }

        if ($form->status !== 'published') {
            throw new \RuntimeException('A target can be replaced once the OPCR is published, and before it is sent for rating.');
        }

        if ($message = PcrWorkflow::formLockMessage($actor, $form)) {
            throw new \RuntimeException($message);
        }

        if (! in_array($actor->role, app(WorkflowSettings::class)->opcr('publisher_roles'), true)) {
            throw new \RuntimeException('Only the person who publishes the OPCR can replace a target.');
        }

        if ($old->closed_at) {
            throw new \RuntimeException('This target has already been replaced.');
        }

        if ($old->replacement_status === 'pending') {
            throw new \RuntimeException('This line is already a replacement waiting for QA.');
        }

        if (PcrTargetReplacement::where('old_indicator_id', $old->id)->where('status', 'pending')->exists()) {
            throw new \RuntimeException('This target is already waiting for QA to approve a replacement.');
        }

        $description = array_key_exists('description', $changes)
            ? (Html::clean($changes['description']) ?? '')
            : $old->description;
        $targetDate = array_key_exists('target_date', $changes)
            ? ($changes['target_date'] ?: null)
            : $old->target_date?->toDateString();
        $budget = array_key_exists('allotted_budget', $changes)
            ? ($changes['allotted_budget'] === null || $changes['allotted_budget'] === '' ? null : $changes['allotted_budget'])
            : $old->allotted_budget;
        $userIds = array_key_exists('user_ids', $changes) ? array_values(array_unique(array_map('intval', $changes['user_ids'] ?? []))) : null;

        if (Html::isBlank($description)) {
            throw new \RuntimeException('The success indicator cannot be empty.');
        }

        if ($this->nothingChanged($old, $description, $targetDate, $budget, $userIds)) {
            throw new \RuntimeException('Change the indicator, the date, the budget, or who is accountable.');
        }

        return DB::transaction(function () use ($actor, $old, $form, $description, $targetDate, $budget, $userIds, $notify) {
            $proposed = PcrIndicator::create([
                'output_id'             => $old->output_id,
                'description'           => $description,
                'allotted_budget'       => $budget,
                'accountable'           => $old->accountable,
                'target_date'           => $targetDate,
                'rating_period_id'      => $old->rating_period_id,
                'sort_order'            => (int) $old->sort_order + 1,
                'replaces_indicator_id' => $old->id,
                'replacement_status'    => 'pending',
            ]);

            if ($userIds === null) {
                $this->copyAssignments($old, $proposed);
            } else {
                $this->placeAssignments($proposed, $userIds, $actor);
            }

            $replacement = PcrTargetReplacement::create([
                'old_indicator_id'     => $old->id,
                'new_indicator_id'     => $proposed->id,
                'proposed_description' => Html::toText($description),
                'proposed_user_ids'    => $userIds,
                'status'               => 'pending',
                'requested_by'         => $actor->id,
            ]);

            if ($notify) {
                $this->tellQa($form, 1);
            }

            return $replacement;
        });
    }

    /** Several edited lines go to QA together. One notice, one decision. */
    public function proposeMany(User $actor, array $rows): array
    {
        if ($rows === []) {
            throw new \RuntimeException('Change the indicator, the date, the budget, or who is accountable.');
        }

        return DB::transaction(function () use ($actor, $rows) {
            $made = [];

            foreach ($rows as $row) {
                $indicator = PcrIndicator::with('output.form')->findOrFail($row['indicator_id']);
                $made[] = $this->propose($actor, $indicator, $row, false);
            }

            $form = PcrIndicator::with('output.form')->findOrFail($rows[0]['indicator_id'])->output->form;
            $this->tellQa($form, count($made));

            return $made;
        });
    }

    public function decideForm(User $actor, int $formId, bool $approve, ?string $note = null): void
    {
        $pending = PcrTargetReplacement::query()
            ->where('status', 'pending')
            ->whereHas('oldIndicator.output', fn ($query) => $query->where('form_id', $formId))
            ->get();

        if ($pending->isEmpty()) {
            throw new \RuntimeException('Nothing is waiting for QA.');
        }

        if (! $approve && trim((string) $note) === '') {
            throw new \RuntimeException('Write why you are sending this back.');
        }

        foreach ($pending as $replacement) {
            $approve
                ? $this->approve($actor, $replacement, false)
                : $this->reject($actor, $replacement, false);
        }

        if ($approve) {
            foreach ($pending->pluck('requested_by')->unique() as $userId) {
                Notification::send(
                    $userId,
                    'approved',
                    'The target changes were approved',
                    'The published OPCR now uses the lines you sent.',
                    $formId,
                    "/forms/{$formId}"
                );
            }

            return;
        }

        foreach ($pending->pluck('requested_by')->unique() as $userId) {
            Notification::send(
                $userId,
                'returned',
                'The target replacement was sent back',
                trim((string) $note),
                $formId,
                "/forms/{$formId}"
            );
        }
    }

    private function tellQa($form, int $count): void
    {
        Notification::sendMany(
            User::whereIn('role', app(WorkflowSettings::class)->opcr('approver_roles'))
                ->where('status', 'active')
                ->pluck('id')
                ->all(),
            'approval',
            $count === 1 ? 'An OPCR line is waiting to be changed' : "{$count} OPCR lines are waiting to be changed",
            'Highlighted cells are the ones that changed. Approve them together.',
            $form->id,
            "/forms/{$form->id}"
        );
    }

    public function approve(User $actor, PcrTargetReplacement $replacement, bool $notifyRequester = true): void
    {
        $this->guardDecision($actor, $replacement, true);

        $formId = null;

        DB::transaction(function () use ($actor, $replacement, &$formId) {
            $old = PcrIndicator::with('output.form')->findOrFail($replacement->old_indicator_id);
            $new = PcrIndicator::findOrFail($replacement->new_indicator_id);
            // QA only approves. The president who sent the change stays the assigner.
            $assigner = User::find($replacement->requested_by) ?? $actor;
            $formId = $old->output?->form_id;

            $replacement->update([
                'status'     => 'approved',
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            // The indicator is the commitment people already wrote against.
            // A new date, budget, or accountable list stays on this same line.
            if (Html::toText($old->description) === Html::toText($new->description)) {
                $note = $this->changeRows($old, $new, $replacement->proposed_user_ids, false);

                $old->update([
                    'target_date'     => $new->target_date,
                    'allotted_budget' => $new->allotted_budget,
                    'accountable'     => is_array($replacement->proposed_user_ids)
                        ? $this->accountableNames($replacement->proposed_user_ids)
                        : $old->accountable,
                ]);

                if (is_array($replacement->proposed_user_ids)) {
                    $this->syncAssignments($old, $replacement->proposed_user_ids, $assigner, true);
                    $this->ensureCommitments($old, $replacement->proposed_user_ids, $assigner);
                }

                if ($note !== []) {
                    $this->tellOpenOwners(
                        $old,
                        'A target linked to your IPCR was updated',
                        'The date, the budget, or who is accountable changed. Your commitment on that line stays.'
                    );
                    $this->remember($old->output->form, $actor, $note);
                }

                $new->delete();

                return;
            }

            $this->remember(
                $old->output->form,
                $actor,
                $this->changeRows($old, $new, $replacement->proposed_user_ids, true)
            );

            $new->update([
                'replacement_status' => 'approved',
                'accountable'        => is_array($replacement->proposed_user_ids)
                    ? $this->accountableNames($replacement->proposed_user_ids)
                    : $old->accountable,
            ]);
            $old->update([
                'closed_at'     => now(),
                'closed_reason' => 'target_replaced',
            ]);

            if (is_array($replacement->proposed_user_ids)) {
                PcrTargetAssignment::where('indicator_id', $new->id)->delete();
                $this->syncAssignments($new, $replacement->proposed_user_ids, $assigner, true);
            } else {
                $this->copyAssignments($old, $new);
            }

            $seen = [];
            $this->restartChildren($old, $new, $seen, $actor, $replacement->proposed_user_ids);

            $assignedIds = is_array($replacement->proposed_user_ids)
                ? $replacement->proposed_user_ids
                : PcrTargetAssignment::where('indicator_id', $new->id)->pluck('user_id')->all();

            if ($assignedIds !== []) {
                $this->ensureCommitments($new, $assignedIds, $assigner);
            }

            $child = $new->openChildren()->first();

            if ($child) {
                app(IndicatorProgressService::class)->recalculateFrom($child);
            }
        });

        if ($notifyRequester && $formId) {
            Notification::send(
                $replacement->requested_by,
                'approved',
                'The target replacement was approved',
                'The published OPCR now uses the line you sent.',
                $formId,
                "/forms/{$formId}"
            );
        }
    }

    public function reject(User $actor, PcrTargetReplacement $replacement, bool $notify = true): void
    {
        $this->guardDecision($actor, $replacement, false);

        DB::transaction(function () use ($actor, $replacement, $notify) {
            $proposedId = $replacement->new_indicator_id;

            $replacement->update([
                'status'           => 'rejected',
                'new_indicator_id' => null,
                'decided_by'       => $actor->id,
                'decided_at'       => now(),
            ]);

            if ($proposedId) {
                PcrIndicator::whereKey($proposedId)->where('replacement_status', 'pending')->delete();
            }

            if (! $notify) {
                return;
            }

            $formId = PcrIndicator::with('output')->find($replacement->old_indicator_id)?->output?->form_id;

            Notification::send(
                $replacement->requested_by,
                'returned',
                'The target replacement was sent back',
                'The current target stays in place.',
                $formId,
                $formId ? "/forms/{$formId}" : null
            );
        });
    }

    private function guardDecision(User $actor, PcrTargetReplacement $replacement, bool $approving): void
    {
        if ($replacement->status !== 'pending' || ! $replacement->new_indicator_id) {
            throw new \RuntimeException('This replacement has already been decided.');
        }

        $rules = app(WorkflowSettings::class);
        $approver = in_array($actor->role, $rules->opcr('approver_roles'), true);
        $publisher = in_array($actor->role, $rules->opcr('publisher_roles'), true);

        if ($approving && ! $approver) {
            throw new \RuntimeException('QA approves a target replacement.');
        }

        if (! $approving && ! $approver && ! $publisher) {
            throw new \RuntimeException('You cannot send this replacement back.');
        }
    }

    /**
     * Close every open commitment hanging off the old line and hand its owner
     * a blank one pointed at the live replacement. Someone taken off the
     * accountable list is closed without a new line. A form that is already
     * rated keeps the commitment it was scored on.
     *
     * @param  list<int>|null  $keptUserIds
     */
    private function restartChildren(PcrIndicator $old, PcrIndicator $liveParent, array &$seen, ?User $actor = null, ?array $keptUserIds = null): void
    {
        $children = PcrIndicator::with('output.form')
            ->where('parent_indicator_id', $old->id)
            ->whereNull('closed_at')
            ->get();
        $officeLine = $old->output?->form?->type === 'opcr';
        $kept = is_array($keptUserIds)
            ? array_map('intval', $keptUserIds)
            : null;

        foreach ($children as $child) {
            if (isset($seen[$child->id])) {
                continue;
            }

            $form = $child->output?->form;

            if (! $form || in_array($form->status, ['rated', 'final', 'appeal'], true)) {
                $seen[$child->id] = true;
                $this->restartChildren($child, $liveParent, $seen, $actor, $keptUserIds);

                continue;
            }

            if ($officeLine && $kept !== null && ! in_array((int) $form->user_id, $kept, true)) {
                $this->closeBranch($child, $seen, 'removed');

                continue;
            }

            $seen[$child->id] = true;

            $child->update([
                'closed_at'     => now(),
                'closed_reason' => 'target_replaced',
            ]);

            $needsApproval = (bool) $form->commitment_approved_at
                || ! in_array($form->status, ['draft', 'returned'], true);
            $approver = $needsApproval ? $this->approverFor($form) : null;

            $blank = PcrIndicator::create([
                'output_id'                  => $child->output_id,
                'description'                => '',
                'parent_indicator_id'        => $liveParent->id,
                'rating_period_id'           => $child->rating_period_id,
                'sort_order'                 => $child->sort_order,
                'replaces_indicator_id'      => $child->id,
                'needs_commitment_approval'  => $needsApproval,
                'line_approver_id'           => $approver?->id,
            ]);

            $this->copyAssignments($child, $blank);
            $this->restartChildren($child, $blank, $seen, $actor, $keptUserIds);

            if (! $needsApproval && $actor) {
                $this->remember($form, $actor, [[
                    'field' => 'Success indicator',
                    'from'  => $this->plain($child->description),
                    'to'    => 'A new commitment was started.',
                ]]);
            }

            Notification::send(
                $form->user_id,
                'approval',
                'A target linked to your IPCR was replaced',
                $needsApproval
                    ? 'Your earlier commitment on that line is closed. Write a new one and send it for approval.'
                    : 'Your earlier commitment on that line is closed. Write the new one on this form.',
                $form->id,
                "/forms/{$form->id}"
            );
        }
    }

    private function approverFor(PcrForm $form): ?User
    {
        foreach ([$form->commitment_approver_id, $form->commitment_approved_by] as $id) {
            if (! $id) {
                continue;
            }

            $person = User::whereKey($id)->where('status', 'active')->first();

            if ($person) {
                return $person;
            }
        }

        return PcrWorkflow::commitmentApprover($form)
            ?? User::where('role', 'president')->where('status', 'active')->first();
    }

    private function remember(PcrForm $form, User $actor, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        ActivityLog::record('PcrForm', $form->id, 'replace', 'These cells changed.', $changes, $actor);
    }

    private function plain(?string $text): string
    {
        $plain = trim(Html::toText($text ?? ''));

        if ($plain === '') {
            return 'None';
        }

        return mb_strlen($plain) > 300 ? mb_substr($plain, 0, 297).'…' : $plain;
    }

    /** @return array<int, array{field: string, from: string, to: string}> */
    private function changeRows(PcrIndicator $old, PcrIndicator $proposed, ?array $userIds, bool $words): array
    {
        $rows = [];

        if ($words && Html::toText($old->description) !== Html::toText($proposed->description)) {
            $rows[] = [
                'field' => 'Success indicator',
                'from'  => $this->plain($old->description),
                'to'    => $this->plain($proposed->description),
            ];
        }

        $oldDate = $old->target_date?->toDateString();
        $newDate = $proposed->target_date?->toDateString();

        if ($oldDate !== $newDate) {
            $rows[] = [
                'field' => 'Target date',
                'from'  => $oldDate ?: 'None',
                'to'    => $newDate ?: 'None',
            ];
        }

        if ((float) ($old->allotted_budget ?? 0) !== (float) ($proposed->allotted_budget ?? 0)) {
            $rows[] = [
                'field' => 'Allotted budget',
                'from'  => $old->allotted_budget === null ? 'None' : (string) $old->allotted_budget,
                'to'    => $proposed->allotted_budget === null ? 'None' : (string) $proposed->allotted_budget,
            ];
        }

        if (is_array($userIds)) {
            $before = PcrTargetAssignment::with('user:id,name')
                ->where('indicator_id', $old->id)
                ->get()
                ->map(fn ($row) => $row->user?->name)
                ->filter()
                ->sort()
                ->values()
                ->all();
            $after = User::whereIn('id', $userIds)->orderBy('name')->pluck('name')->all();

            if ($before !== $after) {
                $rows[] = [
                    'field' => 'Accountable',
                    'from'  => $before === [] ? 'None' : implode(', ', $before),
                    'to'    => $after === [] ? 'None' : implode(', ', $after),
                ];
            }
        }

        return $rows;
    }

    private function nothingChanged(PcrIndicator $old, string $description, $targetDate, $budget, ?array $userIds): bool
    {
        if (Html::toText($old->description) !== Html::toText($description)) {
            return false;
        }

        $oldDate = $old->target_date?->toDateString();
        $newDate = $targetDate ? \Illuminate\Support\Carbon::parse($targetDate)->toDateString() : null;

        if ($oldDate !== $newDate) {
            return false;
        }

        $oldBudget = $old->allotted_budget === null ? null : (float) $old->allotted_budget;
        $newBudget = $budget === null || $budget === '' ? null : (float) $budget;

        if ($oldBudget !== $newBudget) {
            return false;
        }

        if ($userIds === null) {
            return true;
        }

        $current = PcrTargetAssignment::where('indicator_id', $old->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $next = collect($userIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $current === $next;
    }

    /** Rows on the proposal so QA can see who would be accountable. No notice yet. */
    private function placeAssignments(PcrIndicator $line, array $userIds, User $actor): void
    {
        foreach ($userIds as $userId) {
            $person = User::whereKey($userId)->where('status', 'active')->first();

            if (! $person) {
                continue;
            }

            PcrTargetAssignment::create([
                'indicator_id'     => $line->id,
                'user_id'          => $person->id,
                'rating_period_id' => null,
                'assigned_by'      => $actor->id,
                'assigned_by_name' => $actor->name,
            ]);
        }
    }

    private function syncAssignments(PcrIndicator $line, array $userIds, User $actor, bool $releaseWritten = false): void
    {
        $wanted = collect($userIds)->map(fn ($id) => (int) $id)->unique();
        $current = PcrTargetAssignment::where('indicator_id', $line->id)->get();

        foreach ($current as $row) {
            if ($wanted->contains((int) $row->user_id)) {
                continue;
            }

            if ($releaseWritten) {
                $this->releasePerson($line, (int) $row->user_id);
                $row->delete();

                continue;
            }

            app(PcrAssignmentService::class)->withdrawAssignment($row);
        }

        $still = PcrTargetAssignment::where('indicator_id', $line->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        foreach ($wanted as $userId) {
            if ($still->contains($userId)) {
                continue;
            }

            $person = User::whereKey($userId)->where('status', 'active')->first();

            if ($person) {
                app(PcrAssignmentService::class)->assignIndicator($line, $person, $actor);
            }
        }
    }

    /** Close this person's open commitments on the line, including work they handed on. */
    private function releasePerson(PcrIndicator $line, int $userId): void
    {
        $children = PcrIndicator::with('output.form')
            ->where('parent_indicator_id', $line->id)
            ->whereNull('closed_at')
            ->whereHas('output.form', fn ($query) => $query->where('user_id', $userId))
            ->get();
        $seen = [];

        foreach ($children as $child) {
            $this->closeBranch($child, $seen, 'removed');
        }
    }

    /**
     * @param  'removed'|'cascade'  $notice
     */
    private function closeBranch(PcrIndicator $line, array &$seen, string $notice): void
    {
        if (isset($seen[$line->id])) {
            return;
        }

        $seen[$line->id] = true;
        $line->loadMissing('output.form');
        $form = $line->output?->form;

        if ($form && in_array($form->status, ['rated', 'final', 'appeal'], true)) {
            return;
        }

        if (! $line->closed_at) {
            $line->update([
                'closed_at'     => now(),
                'closed_reason' => 'target_replaced',
            ]);
        }

        if ($form?->user_id) {
            Notification::send(
                $form->user_id,
                'approval',
                $notice === 'removed'
                    ? 'You are no longer accountable for an office target'
                    : 'A commitment linked to yours was closed',
                $notice === 'removed'
                    ? 'That commitment is closed. It will not be rated.'
                    : 'The office target changed, so this line is closed and will not be rated.',
                $form->id,
                "/forms/{$form->id}"
            );
        }

        $children = PcrIndicator::with('output.form')
            ->where('parent_indicator_id', $line->id)
            ->whereNull('closed_at')
            ->get();

        foreach ($children as $child) {
            $this->closeBranch($child, $seen, 'cascade');
        }
    }

    /** People newly named, who have no open line yet, get a blank they can write. */
    private function ensureCommitments(PcrIndicator $live, array $userIds, User $actor): void
    {
        $live->loadMissing('output.form');
        $parentForm = $live->output?->form;

        if (! $parentForm || $live->output?->section === 'support') {
            return;
        }

        $covered = PcrIndicator::with('output.form:id,user_id')
            ->where('parent_indicator_id', $live->id)
            ->whereNull('closed_at')
            ->get()
            ->map(fn (PcrIndicator $line) => (int) $line->output?->form?->user_id)
            ->filter()
            ->all();

        $preferred = $live->rating_period_id
            ?? RatingPeriod::where('school_year_id', $parentForm->school_year_id)
                ->orderByDesc('is_active')
                ->orderBy('seq')
                ->value('id');

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if (in_array($userId, $covered, true)) {
                continue;
            }

            $person = User::whereKey($userId)->where('status', 'active')->first();

            if (! $person || $person->role === 'president') {
                continue;
            }

            $forms = PcrForm::query()
                ->where('type', 'ipcr')
                ->where('user_id', $person->id)
                ->where('school_year_id', $parentForm->school_year_id)
                ->whereNotIn('status', ['rated', 'final', 'appeal'])
                ->get();
            $form = $forms->firstWhere('rating_period_id', $preferred) ?? $forms->first();

            if ($form) {
                $this->startBlank($form, $live, $actor);
            }
        }
    }

    private function startBlank(PcrForm $form, PcrIndicator $parent, User $actor): void
    {
        $parent->loadMissing('output');
        $source = $parent->output;
        $section = $source->section === 'strategic' ? 'core' : $source->section;
        $output = PcrOutput::query()
            ->where('form_id', $form->id)
            ->where(function ($query) use ($source, $section) {
                $query->where('parent_output_id', $source->id)
                    ->orWhere('section', $section);
            })
            ->get()
            ->sortBy(fn (PcrOutput $row) => (int) $row->parent_output_id === (int) $source->id ? 0 : 1)
            ->first();

        if (! $output) {
            $output = PcrOutput::create([
                'form_id'          => $form->id,
                'parent_output_id' => $source->id,
                'section'          => $section,
                'title'            => $source->title,
                'assigned_by'      => $actor->id,
                'assigned_by_name' => $actor->name,
                'sort_order'       => (PcrOutput::where('form_id', $form->id)->max('sort_order') ?? 0) + 1,
            ]);
        }

        $needsApproval = (bool) $form->commitment_approved_at
            || ! in_array($form->status, ['draft', 'returned'], true);

        PcrIndicator::create([
            'output_id'                 => $output->id,
            'description'               => '',
            'parent_indicator_id'       => $parent->id,
            'rating_period_id'          => $form->rating_period_id,
            'sort_order'                => (PcrIndicator::where('output_id', $output->id)->max('sort_order') ?? 0) + 1,
            'needs_commitment_approval' => $needsApproval,
            'line_approver_id'          => $needsApproval ? $this->approverFor($form)?->id : null,
        ]);

        Notification::send(
            $form->user_id,
            'approval',
            'A target linked to your IPCR was replaced',
            $needsApproval
                ? 'Write a commitment against it and send it for approval.'
                : 'Write your commitment against it on this form.',
            $form->id,
            "/forms/{$form->id}"
        );
    }

    private function tellOpenOwners(PcrIndicator $line, string $title, string $body): void
    {
        $children = PcrIndicator::with('output.form')
            ->where('parent_indicator_id', $line->id)
            ->whereNull('closed_at')
            ->get();
        $told = [];

        foreach ($children as $child) {
            $form = $child->output?->form;

            if (! $form || isset($told[$form->user_id])) {
                continue;
            }

            $told[$form->user_id] = true;
            Notification::send($form->user_id, 'approval', $title, $body, $form->id, "/forms/{$form->id}");
        }
    }

    private function accountableNames(?array $userIds): ?string
    {
        if (! is_array($userIds)) {
            return null;
        }

        $names = User::query()->whereIn('id', $userIds)->orderBy('name')->pluck('name')->filter()->values();

        if ($names->isEmpty()) {
            return null;
        }

        return mb_substr($names->implode(', '), 0, 255);
    }

    private function copyAssignments(PcrIndicator $from, PcrIndicator $to): void
    {
        PcrTargetAssignment::where('indicator_id', $from->id)->get()->each(function (PcrTargetAssignment $row) use ($to) {
            PcrTargetAssignment::query()->firstOrCreate(
                [
                    'indicator_id'     => $to->id,
                    'user_id'          => $row->user_id,
                    'rating_period_id' => $row->rating_period_id,
                ],
                [
                    'assigned_by'      => $row->assigned_by,
                    'assigned_by_name' => $row->assigned_by_name,
                ]
            );
        });
    }
}
