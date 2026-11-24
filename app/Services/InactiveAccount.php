<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrStatusLog;
use App\Models\User;

/**
 * An inactive account cannot sign in, and a form must not sit with them.
 * Deactivating the account revokes its sessions and moves whatever was
 * waiting. Opening the form, or sending a follow-up, does the same move
 * when a form was already parked there.
 */
class InactiveAccount
{
    public const MESSAGE = 'This account is inactive. Ask an administrator to reactivate it.';

    public const MOVED_NOTE = 'The reviewer account is inactive, so this step now goes to the next active person in that slot.';

    public const STUCK_NOTE = 'The commitment approver account is inactive, and nobody active can take this step.';

    public function seal(User $user): void
    {
        $this->revokeTokens($user);
        $this->sweep();
    }

    public function revokeTokens(User $user): void
    {
        $user->tokens()->update(['revoked' => true]);
    }

    /** Every IPCR still waiting on someone who can no longer sign in. */
    public function sweep(): void
    {
        PcrForm::query()
            ->where('type', 'ipcr')
            ->where('status', 'commitment_review')
            ->whereHas('commitmentApprover', fn ($approver) => $approver->where('status', 'inactive'))
            ->orderBy('id')
            ->get()
            ->each(fn (PcrForm $form) => $this->release($form));

        $statuses = array_values(array_filter(
            PcrWorkflow::stageStatuses(),
            fn (string $status) => isset(PcrWorkflow::REVIEWER_COLUMNS[$status])
        ));

        if ($statuses === []) {
            return;
        }

        PcrForm::query()
            ->where('type', 'ipcr')
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->get()
            ->each(fn (PcrForm $form) => $this->release($form));
    }

    /** Move this form when its current reviewer or commitment approver is inactive. */
    public function release(PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return false;
        }

        $form->loadMissing(['orgUnit', 'owner:id,name', 'stageReviewers']);

        if ($form->status === 'commitment_review') {
            return $this->reassignCommitment($form);
        }

        return $this->advanceReview($form);
    }

    private function advanceReview(PcrForm $form): bool
    {
        $shift = PcrWorkflow::advancePastInactiveReviewer($form);

        if (! $shift) {
            return false;
        }

        $this->tellPeople($form, $shift['from'], $shift['to']);

        return true;
    }

    private function reassignCommitment(PcrForm $form): bool
    {
        $currentId = $form->commitment_approver_id ? (int) $form->commitment_approver_id : null;
        $currentActive = $currentId
            && User::query()->whereKey($currentId)->where('status', 'active')->exists();

        if (! $currentId || $currentActive) {
            return false;
        }

        $next = PcrWorkflow::commitmentApprover($form);

        if ($next && (int) $next->id !== $currentId) {
            $form->commitment_approver_id = $next->id;
            $form->save();

            PcrStatusLog::record($form->id, 'commitment_review', 'commitment_review', self::MOVED_NOTE);
            $this->tellPeople($form, 'commitment_review', 'commitment_review');

            return true;
        }

        $already = PcrStatusLog::query()
            ->where('form_id', $form->id)
            ->where('note', self::STUCK_NOTE)
            ->exists();

        if ($already) {
            return false;
        }

        PcrStatusLog::record($form->id, 'commitment_review', 'commitment_review', self::STUCK_NOTE);

        return true;
    }

    private function tellPeople(PcrForm $form, string $from, string $to): void
    {
        $form->loadMissing(['owner:id,name', 'orgUnit:id,name']);
        $subject = $form->owner?->name ?? $form->orgUnit?->name ?? 'A staff member';
        $label = strtoupper($form->type);

        $this->tell(
            $form->user_id ? (int) $form->user_id : null,
            'review',
            $from === $to ? 'Your IPCR has a new reviewer' : 'Your IPCR moved on',
            'The reviewer account is inactive, so the form went to the next person who can still sign in.',
            $form->id
        );

        if ($to === 'qa_rating') {
            Notification::sendMany(
                User::query()
                    ->whereIn('role', app(WorkflowSettings::class)->raterRoles())
                    ->where('status', 'active')
                    ->pluck('id')
                    ->all(),
                'rating',
                "{$label} from {$subject} is ready for rating",
                'The previous reviewer account is inactive. It is waiting in your queue.',
                $form->id,
                $form->queueLink()
            );

            return;
        }

        $reviewerId = $to === 'commitment_review'
            ? ($form->commitment_approver_id ? (int) $form->commitment_approver_id : null)
            : PcrWorkflow::reviewerId($form, $to);

        $this->tell(
            $reviewerId,
            'review',
            "{$label} from {$subject} needs your review",
            'The previous reviewer account is inactive. It is waiting in your queue.',
            $form->id,
            $form->queueLink()
        );
    }

    private function tell(?int $userId, string $type, string $title, string $body, int $formId, ?string $link = null): void
    {
        if (! $userId || ! User::query()->whereKey($userId)->where('status', 'active')->exists()) {
            return;
        }

        Notification::send($userId, $type, $title, $body, $formId, $link);
    }
}
