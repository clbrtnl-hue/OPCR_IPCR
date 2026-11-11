<?php

namespace App\Services;

use App\Models\OrgUnit;
use App\Models\PcrForm;
use App\Models\PcrFormReviewer;
use App\Models\PcrStatusLog;
use App\Models\PcrTargetAssignment;
use App\Models\RatingPeriod;
use App\Models\User;

class PcrWorkflow
{
    /**
     * An IPCR's commitments are approved before any tasks or cascade, then
     * reviewed up the hierarchy. An OPCR is planned
     * first: the president drafts it, QA approves it, the president publishes
     * it — only then is it something other people's IPCRs can be tied to — and
     * it is rated once, in December, with the last period, on the same document.
     */
    public const TRANSITIONS = [
        'ipcr' => [
            'draft'       => ['commitment_review'],
            'returned'    => ['commitment_review', 'head_review', 'dean_review', 'director_review', 'vp_review', 'qa_rating'],
            'commitment_review' => ['commitments_approved', 'returned'],
            'commitments_approved' => ['head_review', 'dean_review', 'director_review', 'vp_review', 'qa_rating'],
            'head_review' => ['dean_review', 'director_review', 'vp_review', 'qa_rating', 'returned'],
            'dean_review' => ['director_review', 'vp_review', 'qa_rating', 'returned'],
            'director_review' => ['vp_review', 'qa_rating', 'returned'],
            'vp_review'   => ['qa_rating', 'returned'],
            'qa_rating'   => ['rated', 'returned'],
            'rated'       => ['final', 'qa_rating'],
            'final'       => [],
        ],
        'opcr' => [
            'draft'       => ['qa_approval'],
            'returned'    => ['qa_approval', 'qa_rating'],
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
            'dean_review' => ['employee', 'program_head', 'vp'],
            'director_review' => ['employee', 'program_head', 'vp'],
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
        'dean_review' => 'dean_reviewer_id',
        'vp_review'   => 'vp_reviewer_id',
    ];

    /** Statuses at or past which an OPCR's targets are visible to other people. */
    public const OPCR_VISIBLE = ['published', 'qa_rating', 'rated', 'final'];

    /**
     * Who reviews this form, resolved from the ratee's place in the hierarchy.
     * Head is the person named on the ratee's own unit. Dean, VP, and extra
     * steps use that unit first, then the parent if the slot is empty. A stage
     * whose reviewer is absent — or would be the ratee reviewing themselves —
     * is null, and the chain skips it. A VP with nobody above them therefore
     * goes straight to QA.
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
        $map      = [];

        foreach (app(WorkflowSettings::class)->reviewStages() as $stage) {
            $status = $stage['status'] ?? '';
            $column = self::REVIEWER_COLUMNS[$status] ?? null;
            $source = $stage['source'] ?? 'unit_slot';

            // QA is a role gate. It has no named reviewer on the form.
            if ($source === 'role' && ! $column) {
                continue;
            }

            $id = $source === 'unit_slot'
                ? $notTheRatee($unit?->reviewerIdForSlotInChain($stage['slot'] ?? ''))
                : $notTheRatee(
                    User::where('role', $stage['role'] ?? '')->where('status', 'active')->value('id')
                );

            $map[$status] = $id;

            if ($column) {
                $resolved[$column] = $id;
            }
        }

        $form->resolvedReviewerMap = $map;

        if ($form->exists) {
            PcrFormReviewer::sync($form, $map);
        }

        return $resolved + [
            'head_reviewer_id' => null,
            'dean_reviewer_id' => null,
            'vp_reviewer_id'   => null,
        ];
    }

    /** Statuses an IPCR can stop at, in the order Setup saved them. */
    public static function stageStatuses(): array
    {
        return array_column(app(WorkflowSettings::class)->reviewStages(), 'status');
    }

    public static function isReviewDestination(string $status): bool
    {
        return in_array($status, self::stageStatuses(), true);
    }

    /** Who was resolved for this stage, including a step added after the built-in three. */
    public static function reviewerId(PcrForm $form, string $status): ?int
    {
        $map = $form->resolvedReviewerMap;

        if (array_key_exists($status, $map)) {
            return $map[$status] ? (int) $map[$status] : null;
        }

        $column = self::REVIEWER_COLUMNS[$status] ?? null;

        if ($column && $form->{$column}) {
            return (int) $form->{$column};
        }

        $row = $form->relationLoaded('stageReviewers')
            ? $form->stageReviewers->firstWhere('stage', $status)
            : ($form->exists ? $form->stageReviewers()->where('stage', $status)->first() : null);

        return $row?->reviewer_id ? (int) $row->reviewer_id : null;
    }

    public static function chainFor(PcrForm $form): array
    {
        $form->loadMissing('stageReviewers.reviewer:id,name,position_title');

        return array_map(function (array $stage) use ($form) {
            $status = $stage['status'];
            $id     = self::reviewerId($form, $status);
            $row    = $form->stageReviewers->firstWhere('stage', $status);
            $person = $row?->reviewer;

            if (! $person && $id) {
                $person = User::query()->find($id, ['id', 'name', 'position_title']);
            }

            $named = ($stage['source'] ?? 'unit_slot') !== 'role'
                || isset(self::REVIEWER_COLUMNS[$status]);

            return [
                'status'      => $status,
                'label'       => $stage['label'] ?? $status,
                'reviewer_id' => $id,
                'reviewer'    => $person ? $person->only(['id', 'name', 'position_title']) : null,
                'filled'      => $named ? (bool) $id : true,
            ];
        }, app(WorkflowSettings::class)->reviewStages());
    }

    /** The next status that actually has somebody to act on it. */
    public static function nextStatusFor(PcrForm $form, string $from): ?string
    {
        if ($form->type !== 'ipcr') {
            return self::TRANSITIONS[$form->type][$from][0] ?? null;
        }

        $stages = app(WorkflowSettings::class)->reviewStages();

        // Where in the configured chain we are. Rating starts once commitments
        // are approved, or when a rated form is sent back. A draft cannot skip
        // that approval and land on a reviewer.
        $position = in_array($from, ['returned', 'commitments_approved'], true)
            ? -1
            : array_search($from, array_column($stages, 'status'), true);

        if ($position === false) {
            return self::TRANSITIONS[$form->type][$from][0] ?? null;
        }

        // The first stage after here that somebody actually fills.
        foreach (array_slice($stages, $position + 1) as $stage) {
            if (self::stageFilled($form, $stage) || ! ($stage['skippable'] ?? true)) {
                return $stage['status'];
            }
        }

        return null;
    }

    /** Whoever is named for this stage on this form — not whoever holds a role. */
    /**
     * The head scores a faculty IPCR, the VP scores a head's IPCR and may
     * change the faculty score, and QA scores a VP's IPCR and may change any
     * score that reaches them. QA also scores their own IPCR and their staff's.
     */
    public static function mayScore(User $user, PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return $form->status === 'qa_rating' && $user->role === 'qa';
        }

        if ((int) self::reviewerId($form, $form->status) === (int) $user->id) {
            return true;
        }

        return $form->status === 'qa_rating' && $user->role === 'qa';
    }

    /** QA closes the rating. On their own staff they close it without sending it up. */
    public static function mayFinalize(User $user, PcrForm $form): bool
    {
        if ($user->role !== 'qa') {
            return false;
        }

        if ($form->status === 'qa_rating') {
            return true;
        }

        return (int) self::reviewerId($form, $form->status) === (int) $user->id;
    }

    public static function isReviewerFor(User $user, PcrForm $form, string $status): bool
    {
        return (int) self::reviewerId($form, $status) === (int) $user->id;
    }

    public static function canTransition(string $type, string $from, string $to): bool
    {
        if (in_array($to, self::TRANSITIONS[$type][$from] ?? [], true)) {
            return true;
        }

        if ($type !== 'ipcr' || $to === $from) {
            return false;
        }

        $stages = self::stageStatuses();

        if ($to === 'returned' && in_array($from, $stages, true)) {
            return true;
        }

        // Draft stays in the commitment lane. Rating starts at commitments_approved,
        // and a return from rating may re-enter any later stage.
        $fromPos = in_array($from, ['returned', 'commitments_approved'], true)
            ? -1
            : array_search($from, $stages, true);
        $toPos   = array_search($to, $stages, true);

        return $fromPos !== false && $toPos !== false && $toPos > $fromPos;
    }

    /** A role gate such as QA is always filled. A named step is filled when somebody was resolved. */
    private static function stageFilled(PcrForm $form, array $stage): bool
    {
        $status = $stage['status'] ?? '';
        $source = $stage['source'] ?? 'unit_slot';

        if ($source === 'role' && ! isset(self::REVIEWER_COLUMNS[$status])) {
            return true;
        }

        return (bool) self::reviewerId($form, $status);
    }

    public static function actorMayMoveTo(User $user, string $type, string $to): bool
    {
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

    /**
     * The college OPCR stays published until December of its school year,
     * when the last period (July to December) is rated.
     */
    public static function opcrRatingMonthOpen(PcrForm $form): bool
    {
        $form->loadMissing('schoolYear');
        $year = $form->schoolYear;

        if (! $year?->start_date || ! $year?->end_date) {
            return false;
        }

        $today = now()->startOfDay();

        return $today->month === 12
            && $today->greaterThanOrEqualTo($year->start_date->copy()->startOfDay())
            && $today->lessThanOrEqualTo($year->end_date->copy()->endOfDay());
    }

    /**
     * The period a write belongs to. An IPCR names its own; a year-wide OPCR
     * falls back to the active period of its school year.
     */
    public static function periodForWrite(PcrForm $form, ?int $explicit = null): ?int
    {
        if ($explicit) {
            return (int) $explicit;
        }

        if ($form->rating_period_id) {
            return (int) $form->rating_period_id;
        }

        $id = RatingPeriod::where('school_year_id', $form->school_year_id)
            ->orderByDesc('is_active')
            ->orderBy('seq')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /** Null when the write may proceed. A locked period is finished. */
    public static function lockMessage(User $user, ?int $periodId): ?string
    {
        if (! $periodId) {
            return null;
        }

        $period = RatingPeriod::find($periodId);

        if (! $period || ! $period->isLockedFor($user)) {
            return null;
        }

        return "{$period->label} is locked. It is view-only now — ask an administrator to unlock it.";
    }

    /**
     * An IPCR is locked with its own review period. A year-wide OPCR stays
     * writable until every period of that school year is locked.
     */
    public static function formLockMessage(User $user, PcrForm $form, ?int $explicit = null): ?string
    {
        if ($form->type !== 'opcr') {
            return self::lockMessage($user, self::periodForWrite($form, $explicit));
        }

        $periods = RatingPeriod::where('school_year_id', $form->school_year_id)->orderBy('seq')->get();

        if ($periods->isEmpty() || $periods->contains(fn (RatingPeriod $period) => ! $period->isLockedFor($user))) {
            return null;
        }

        $form->loadMissing('schoolYear');
        $label = $form->schoolYear?->label ?: 'This school year';

        return "{$label} is closed. It is view-only now — ask an administrator to unlock a review period.";
    }

    /**
     * A return from rating goes back to rating. A return from approval, or a
     * return with no trail, goes back to approval.
     */
    public static function opcrResubmitStatus(PcrForm $form): string
    {
        return self::returnedFromRating($form) ? 'qa_rating' : 'qa_approval';
    }

    public static function returnedFromRating(PcrForm $form): bool
    {
        $logs = $form->relationLoaded('statusLogs')
            ? $form->statusLogs
            : PcrStatusLog::where('form_id', $form->id)->get();

        $log = $logs->where('to_status', 'returned')->sortByDesc('id')->first();

        return $log?->from_status === 'qa_rating';
    }

    /** A published OPCR, including one sent back from rating, stays available to IPCRs. */
    public static function opcrIsVisible(PcrForm $form): bool
    {
        if (in_array($form->status, self::OPCR_VISIBLE, true)) {
            return true;
        }

        return $form->status === 'returned' && self::returnedFromRating($form);
    }

    public static function owns(User $user, PcrForm $form): bool
    {
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
        if (in_array($user->role, ['admin', 'qa', 'hr', 'president'], true)) {
            return true;
        }

        if (self::owns($user, $form)) {
            return true;
        }

        if ($form->commitment_approver_id && (int) $form->commitment_approver_id === (int) $user->id) {
            return true;
        }

        if (self::isReviewerFor($user, $form, $form->status)) {
            return true;
        }

        if (in_array($user->role, ['program_head', 'vp'], true)) {
            $overseen = OrgUnit::overseenIds((int) $user->id);

            if (in_array((int) $form->org_unit_id, $overseen, true)) {
                return true;
            }

            // A head, dean, director, or VP follows the person, not the office
            // stamped on the form. Someone moved into their unit — or the IPCR
            // was filed under the parent — is still theirs to open. Read the
            // unit from the account: the form often arrives with the owner
            // already loaded and that column left out.
            $ownerUnitId = $form->type === 'ipcr' && $form->user_id
                ? User::whereKey($form->user_id)->value('org_unit_id')
                : null;

            if ($ownerUnitId && in_array((int) $ownerUnitId, $overseen, true)) {
                return true;
            }

            return $user->role === 'vp'
                && $form->type === 'opcr'
                && (int) $form->org_unit_id === (int) $user->org_unit_id;
        }

        return false;
    }

    public static function canEditCommitments(User $user, PcrForm $form): bool
    {
        if (! $form->isEditable() || ! self::owns($user, $form)) {
            return false;
        }

        // Once commitments are approved they stay as approved, including when
        // the rating is sent back. Only a return from commitment review reopens them.
        return $form->type !== 'ipcr' || ! $form->commitment_approved_at;
    }

    /** Tasks stay closed until commitments are approved. A form already in rating is not sent back to that gate. */
    public static function mayRecordWork(PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return true;
        }

        if ($form->commitment_approved_at) {
            return true;
        }

        return ! in_array($form->status, ['draft', 'returned', 'commitment_review'], true);
    }

    /** Cascade opens after approval and closes again once the form is in rating. */
    public static function mayHandOut(PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return true;
        }

        return (bool) $form->commitment_approved_at
            && in_array($form->status, ['commitments_approved', 'returned'], true);
    }

    /**
     * Who approves this IPCR's commitments. People named on the published OPCR
     * go to the president. Everyone else goes to the person who handed them the
     * work, then to the head named on their unit.
     */
    public static function commitmentApprover(PcrForm $form): ?User
    {
        $form->loadMissing('owner', 'orgUnit');
        $owner = $form->owner;

        if (! $owner) {
            return null;
        }

        if (self::accountableOnPublishedOpcr($form, $owner)) {
            return User::where('role', 'president')->where('status', 'active')->first();
        }

        $assignerId = PcrTargetAssignment::query()
            ->where('user_id', $owner->id)
            ->where('assigned_by', '!=', $owner->id)
            ->when($form->rating_period_id, function ($query) use ($form) {
                $query->where(function ($inner) use ($form) {
                    $inner->where('rating_period_id', $form->rating_period_id)
                        ->orWhereNull('rating_period_id');
                });
            })
            ->whereHas('indicator.output.form', function ($query) use ($form) {
                $query->where('type', 'ipcr')
                    ->where('school_year_id', $form->school_year_id);
            })
            ->latest('id')
            ->value('assigned_by');

        if ($assignerId) {
            $assigner = User::whereKey($assignerId)->where('status', 'active')->first();

            if ($assigner && (int) $assigner->id !== (int) $owner->id && $assigner->role !== 'president') {
                return $assigner;
            }
        }

        foreach (app(WorkflowSettings::class)->reviewStages() as $stage) {
            if (($stage['source'] ?? 'unit_slot') !== 'unit_slot') {
                continue;
            }

            $id = $form->orgUnit?->reviewerIdForSlotInChain($stage['slot'] ?? '');

            if (! $id || (int) $id === (int) $owner->id) {
                continue;
            }

            $person = User::whereKey($id)->where('status', 'active')->first();

            if ($person && $person->role !== 'president') {
                return $person;
            }
        }

        return null;
    }

    /** A short phrase for the send button: "the President" or the person's name. */
    public static function commitmentApproverLabel(?User $approver): ?string
    {
        if (! $approver) {
            return null;
        }

        return $approver->role === 'president' ? 'the President' : $approver->name;
    }

    private static function accountableOnPublishedOpcr(PcrForm $form, User $owner): bool
    {
        return PcrTargetAssignment::query()
            ->where('user_id', $owner->id)
            ->when($form->rating_period_id, function ($query) use ($form) {
                $query->where(function ($inner) use ($form) {
                    $inner->where('rating_period_id', $form->rating_period_id)
                        ->orWhereNull('rating_period_id');
                });
            })
            ->whereHas('indicator.output.form', function ($query) use ($form) {
                $query->where('type', 'opcr')
                    ->where('school_year_id', $form->school_year_id)
                    ->whereIn('status', self::OPCR_VISIBLE);
            })
            ->exists();
    }
}
