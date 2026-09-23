<?php

namespace App\Services;

use App\Models\PcrForm;
use App\Models\User;

class PcrWorkflow
{
    /**
     * An IPCR is written then reviewed up the hierarchy. An OPCR is planned
     * first: admin drafts it, QA approves it, admin publishes it — only then is
     * it something other people's IPCRs can be tied to — and it is rated later,
     * at each period's end, on the same document.
     */
    public const TRANSITIONS = [
        'ipcr' => [
            // A stage with no reviewer is skipped, so submitting may land on any
            // of the first three; PcrWorkflow::nextStatusFor picks the real one.
            'draft'       => ['head_review', 'vp_review', 'qa_rating'],
            'returned'    => ['head_review', 'vp_review', 'qa_rating'],
            'head_review' => ['vp_review', 'qa_rating', 'returned'],
            'vp_review'   => ['qa_rating', 'returned'],
            'qa_rating'   => ['rated', 'returned'],
            'rated'       => ['final', 'qa_rating'],
            'final'       => [],
        ],
        'opcr' => [
            'draft'       => ['qa_approval'],
            'returned'    => ['qa_approval'],
            'qa_approval' => ['approved', 'returned'],
            'approved'    => ['published', 'draft'],
            'published'   => ['qa_rating', 'draft'],
            'qa_rating'   => ['rated', 'returned'],
            'rated'       => ['final', 'qa_rating'],
            'final'       => [],
        ],
    ];

    public const ACTOR_ROLES = [
        'ipcr' => [
            'head_review' => ['employee', 'program_head', 'vp'],
            'vp_review'   => ['program_head'],
            'qa_rating'   => ['vp'],
            'rated'       => ['qa'],
            'final'       => ['qa', 'president'],
            'returned'    => ['program_head', 'vp', 'qa'],
        ],
        'opcr' => [
            'qa_approval' => ['president'],
            'approved'    => ['qa'],
            'published'   => ['president'],
            'draft'       => ['president'],   // unpublish
            'qa_rating'   => ['qa'],
            'rated'       => ['qa'],
            'final'       => ['qa', 'president'],
            'returned'    => ['qa'],
        ],
    ];

    /** Which form column carries the reviewer resolved for each named stage. */
    public const REVIEWER_COLUMNS = [
        'head_review' => 'head_reviewer_id',
        'vp_review'   => 'vp_reviewer_id',
    ];

    /** Statuses at or past which an OPCR's targets are visible to other people. */
    public const OPCR_VISIBLE = ['published', 'qa_rating', 'rated', 'final'];

    /**
     * Who reviews this form, resolved from the ratee's place in the hierarchy.
     * A stage whose reviewer is absent — or would be the ratee reviewing
     * themselves — is null, and the chain skips it. A ratee with nobody above
     * them in their unit (a VP sitting in the college unit) therefore goes
     * straight to QA.
     */
    public static function resolveReviewers(PcrForm $form): array
    {
        $unit    = $form->orgUnit;
        $rateeId = $form->user_id ? (int) $form->user_id : null;

        $notTheRatee = function ($candidate) use ($rateeId) {
            $candidate = $candidate ? (int) $candidate : null;

            return $candidate && $candidate !== $rateeId ? $candidate : null;
        };

        $resolved = [];

        foreach (app(WorkflowSettings::class)->reviewStages() as $stage) {
            $column = self::REVIEWER_COLUMNS[$stage['status']] ?? null;

            if (! $column) {
                continue;   // a stage held by a role, e.g. QA, needs no named reviewer
            }

            $resolved[$column] = ($stage['source'] ?? 'unit_slot') === 'unit_slot'
                ? $notTheRatee($unit?->{$stage['slot'] ?? ''})
                : $notTheRatee(
                    User::where('role', $stage['role'] ?? '')->where('status', 'active')->value('id')
                );
        }

        return $resolved + ['head_reviewer_id' => null, 'vp_reviewer_id' => null];
    }

    /** The next status that actually has somebody to act on it. */
    public static function nextStatusFor(PcrForm $form, string $from): ?string
    {
        if ($form->type !== 'ipcr') {
            return self::TRANSITIONS[$form->type][$from][0] ?? null;
        }

        $stages = app(WorkflowSettings::class)->reviewStages();

        // Where in the configured chain we are; a submission starts before it.
        $position = in_array($from, ['draft', 'returned'], true)
            ? -1
            : array_search($from, array_column($stages, 'status'), true);

        if ($position === false) {
            return self::TRANSITIONS[$form->type][$from][0] ?? null;
        }

        // The first stage after here that somebody actually fills.
        foreach (array_slice($stages, $position + 1) as $stage) {
            $column = self::REVIEWER_COLUMNS[$stage['status']] ?? null;

            if (! $column || $form->{$column}) {
                return $stage['status'];
            }

            if (! ($stage['skippable'] ?? true)) {
                return $stage['status'];
            }
        }

        return null;
    }

    /** Whoever is named for this stage on this form — not whoever holds a role. */
    public static function isReviewerFor(User $user, PcrForm $form, string $status): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return match ($status) {
            'head_review' => (int) $form->head_reviewer_id === (int) $user->id,
            'vp_review'   => (int) $form->vp_reviewer_id === (int) $user->id,
            default       => false,
        };
    }

    public static function canTransition(string $type, string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$type][$from] ?? [], true);
    }

    public static function actorMayMoveTo(User $user, string $type, string $to): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($type === 'opcr') {
            $rules = app(WorkflowSettings::class);

            $configured = match ($to) {
                'qa_approval' => $rules->opcr('creator_roles'),
                'approved'    => $rules->opcr('approver_roles'),
                'published',
                'draft'       => $rules->opcr('publisher_roles'),
                'qa_rating'   => $rules->opcr('rating_trigger_roles'),
                default       => null,
            };

            if ($configured !== null) {
                return in_array($user->role, $configured, true);
            }
        }

        return in_array($user->role, self::ACTOR_ROLES[$type][$to] ?? [], true);
    }

    /** Only a published OPCR is something other people's IPCRs may point at. */
    public static function opcrIsVisible(PcrForm $form): bool
    {
        return in_array($form->status, self::OPCR_VISIBLE, true);
    }

    public static function owns(User $user, PcrForm $form): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($form->type === 'ipcr') {
            return (int) $form->user_id === (int) $user->id;
        }

        // Whoever the organization says opens the OPCR owns it, wherever they
        // sit — the president writes the college's document from the college
        // unit, not from the office it happens to name.
        $rules = app(WorkflowSettings::class);

        if (in_array($user->role, $rules->opcr('creator_roles'), true)
            || in_array($user->role, $rules->opcr('publisher_roles'), true)
        ) {
            return true;
        }

        return $user->role === 'program_head'
            && (int) $form->org_unit_id === (int) $user->org_unit_id;
    }

    public static function canView(User $user, PcrForm $form): bool
    {
        if (in_array($user->role, ['admin', 'qa', 'president'], true)) {
            return true;
        }

        if (self::owns($user, $form)) {
            return true;
        }

        if ($user->role === 'program_head') {
            return (int) $form->orgUnit?->head_user_id === (int) $user->id;
        }

        if ($user->role === 'vp') {
            return (int) $form->orgUnit?->vp_user_id === (int) $user->id
                || ($form->type === 'opcr' && (int) $form->org_unit_id === (int) $user->org_unit_id);
        }

        return false;
    }

    public static function canEditCommitments(User $user, PcrForm $form): bool
    {
        return $form->isEditable() && self::owns($user, $form);
    }
}
