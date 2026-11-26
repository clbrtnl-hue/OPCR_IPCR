<?php

namespace App\Services;

use App\Models\OrgUnit;
use App\Models\PcrIndicator;
use App\Support\Html;
use App\Models\OrgUnitReviewer;
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
     * it — only then is it something other people's IPCRs can be tied to.
     * The president rates that same document and closes it. Rating is not
     * sent to QA.
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
            'qa_rating'   => ['rated', 'final', 'returned'],
            'rated'       => ['final'],
            'final'       => [],
        ],
        'opcr' => [
            'draft'       => ['qa_approval'],
            'returned'    => ['qa_approval', 'qa_rating'],
            'qa_approval' => ['approved', 'returned'],
            'approved'    => ['published', 'draft'],
            'published'   => ['qa_rating'],
            'qa_rating'   => ['rated', 'final', 'returned'],
            'rated'       => ['final'],
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
            'final'       => ['qa'],
            'returned'    => ['program_head', 'vp', 'qa'],
        ],
        'opcr' => [
            'qa_approval' => ['president'],
            'approved'    => ['qa'],
            'published'   => ['president'],
            'draft'       => ['president'],   // pull an approved OPCR back before it is published
            'qa_rating'   => ['qa'],
            'rated'       => ['qa'],
            'final'       => ['qa'],
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
     * steps use that unit first, then the parent if the slot is empty or the
     * named account is inactive. A stage whose reviewer is absent — or would
     * be the ratee reviewing themselves — is null, and the chain skips it. A
     * VP with nobody active above them therefore goes straight to QA.
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
        $straightToQa = $form->type === 'ipcr' && self::headsUnitsUnderDifferentVps($rateeId);

        foreach (app(WorkflowSettings::class)->reviewStages() as $stage) {
            $status = $stage['status'] ?? '';
            $column = self::REVIEWER_COLUMNS[$status] ?? null;
            $source = $stage['source'] ?? 'unit_slot';

            // QA is a role gate. It has no named reviewer on the form.
            if ($source === 'role' && ! $column) {
                continue;
            }

            // A head of offices under two different VPs has no single VP to
            // send their own form to. Those stages are left empty, so the
            // form goes to QA. Their staff still reach them as head.
            $id = $source === 'unit_slot' && $straightToQa
                ? null
                : ($source === 'unit_slot'
                    ? $notTheRatee($unit?->reviewerIdForSlotInChain($stage['slot'] ?? ''))
                    : $notTheRatee(
                        User::where('role', $stage['role'] ?? '')->where('status', 'active')->value('id')
                    ));

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

    /** True when this person is Head of units that report to two different VPs. */
    private static function headsUnitsUnderDifferentVps(?int $rateeId): bool
    {
        if (! $rateeId) {
            return false;
        }

        $vps = [];

        $unitIds = OrgUnitReviewer::query()
            ->where('user_id', $rateeId)
            ->where('slot', 'head_user_id')
            ->pluck('org_unit_id');

        foreach ($unitIds as $unitId) {
            $unit = OrgUnit::query()->find($unitId);
            $vp   = $unit?->reviewerIdForSlotInChain('vp_user_id');

            if ($vp && (int) $vp !== $rateeId) {
                $vps[(int) $vp] = true;
            }
        }

        return count($vps) >= 2;
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
        $toPresident = self::qaIpcrGoesToPresident($form);
        $president   = $toPresident
            ? User::query()->where('role', 'president')->where('status', 'active')->first(['id', 'name', 'position_title'])
            : null;

        return array_map(function (array $stage) use ($form, $toPresident, $president) {
            $status = $stage['status'];

            // QA writes the IPCR. The president is the one who rates it.
            if ($toPresident && $status === 'qa_rating') {
                return [
                    'status'      => 'qa_rating',
                    'label'       => 'With the President',
                    'reviewer_id' => $president?->id,
                    'reviewer'    => $president?->only(['id', 'name', 'position_title']),
                    'filled'      => true,
                ];
            }

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

    /**
     * A form parked on a reviewer who can no longer sign in. Re-resolve the
     * chain and, when this step is now empty, move it to the next one that
     * has somebody. Returns the move when the status or the person changed.
     *
     * @return array{from: string, to: string}|null
     */
    public static function advancePastInactiveReviewer(PcrForm $form): ?array
    {
        if ($form->type !== 'ipcr' || ! $form->exists || ! self::isReviewDestination($form->status)) {
            return null;
        }

        $status = $form->status;
        $stage  = collect(app(WorkflowSettings::class)->reviewStages())->firstWhere('status', $status);

        if (! $stage) {
            return null;
        }

        $source = $stage['source'] ?? 'unit_slot';

        if ($source === 'role' && ! isset(self::REVIEWER_COLUMNS[$status])) {
            return null;
        }

        $currentId = self::reviewerId($form, $status);

        if ($currentId && User::query()->whereKey($currentId)->where('status', 'active')->exists()) {
            return null;
        }

        $form->forceFill(self::resolveReviewers($form));

        if (self::stageFilled($form, $stage)) {
            $replacement = self::reviewerId($form, $status);

            if (! $replacement || (int) $replacement === (int) $currentId) {
                return null;
            }

            $form->save();

            PcrStatusLog::record(
                $form->id,
                $status,
                $status,
                'The reviewer account is inactive, so this step now goes to the next active person in that slot.'
            );

            return ['from' => $status, 'to' => $status];
        }

        $next = self::nextStatusFor($form, $status);

        if (! $next || $next === $status) {
            $form->save();

            return null;
        }

        $form->status = $next;
        $form->save();

        PcrStatusLog::record(
            $form->id,
            $status,
            $next,
            'Moved on because the reviewer account is inactive.'
        );

        return ['from' => $status, 'to' => $next];
    }

    /** The next status that actually has somebody to act on it. */
    public static function nextStatusFor(PcrForm $form, string $from): ?string
    {
        if ($form->type !== 'ipcr') {
            return self::TRANSITIONS[$form->type][$from][0] ?? null;
        }

        $stages = app(WorkflowSettings::class)->reviewStages();
        $statuses = array_column($stages, 'status');

        // A return from review or rating resumes at that same step. A first
        // submission, and a return that never entered review, starts at the
        // beginning. Commitment approval is a separate lane.
        if ($from === 'returned') {
            $back = self::reviewReturnStage($form);
            $resume = $back !== null ? array_search($back, $statuses, true) : false;

            if ($resume !== false) {
                foreach (array_slice($stages, $resume) as $stage) {
                    // An empty step has nobody who can open the form, so it is
                    // passed over. Stopping there is what leaves the form stuck.
                    if (self::stageFilled($form, $stage)) {
                        return $stage['status'];
                    }
                }

                return null;
            }
        }

        $position = $from === 'commitments_approved'
            ? -1
            : array_search($from, $statuses, true);

        if ($position === false) {
            return self::TRANSITIONS[$form->type][$from][0] ?? null;
        }

        // The first stage after here that somebody actually fills.
        // An empty step is passed over even when it was marked required —
        // a form cannot wait on a person who was never named.
        foreach (array_slice($stages, $position + 1) as $stage) {
            if (self::stageFilled($form, $stage)) {
                return $stage['status'];
            }
        }

        return null;
    }

    /**
     * The owner may optionally score their own IPCR while it is still with
     * them. After that, the head scores a faculty IPCR and may change that
     * score, the VP may change the score that reaches them, and QA may change
     * any score that reaches them or leave it. The president scores QA's own
     * IPCR and closes it, and scores the published college OPCR and closes that.
     */
    public static function mayScore(User $user, PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return ($form->status === 'qa_rating' && self::isRater($user))
                || self::presidentRatesOwnOpcr($user, $form);
        }

        if ($form->status === 'qa_rating' && self::qaIpcrGoesToPresident($form)) {
            return $user->role === 'president';
        }

        if (self::ownerMaySelfRate($user, $form)) {
            return true;
        }

        if ((int) self::reviewerId($form, $form->status) === (int) $user->id) {
            return true;
        }

        return $form->status === 'qa_rating' && self::isRater($user);
    }

    /** Optional. Only while the finished IPCR is still with its owner, before review. */
    public static function ownerMaySelfRate(User $user, PcrForm $form): bool
    {
        return $form->type === 'ipcr'
            && self::owns($user, $form)
            && (bool) $form->commitment_approved_at
            && in_array($form->status, ['commitments_approved', 'returned'], true);
    }

    /**
     * The president closes the college OPCR from the published document.
     * A return from an earlier QA rating comes back to the president as well.
     */
    public static function presidentRatesOwnOpcr(User $user, PcrForm $form): bool
    {
        if ($form->type !== 'opcr' || ! self::owns($user, $form)) {
            return false;
        }

        $rules = app(WorkflowSettings::class);

        if (! in_array($user->role, $rules->opcr('rating_trigger_roles'), true)) {
            return false;
        }

        if ($form->status === 'published') {
            return true;
        }

        return $form->status === 'returned' && self::returnedFromRating($form);
    }

    /** The rating role closes the rating. On their own staff they close it without sending it up. */
    public static function mayFinalize(User $user, PcrForm $form): bool
    {
        if (self::presidentRatesOwnOpcr($user, $form)) {
            return true;
        }

        if ($form->status === 'qa_rating' && self::qaIpcrGoesToPresident($form)) {
            return $user->role === 'president';
        }

        if (! self::isRater($user)) {
            return false;
        }

        if ($form->status === 'qa_rating') {
            return true;
        }

        return (int) self::reviewerId($form, $form->status) === (int) $user->id;
    }

    /**
     * QA's own IPCR is rated by the president. The rating step stays
     * qa_rating so it opens on the Rating tab, and QA's queue leaves it out.
     */
    public static function qaIpcrGoesToPresident(PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return false;
        }

        $owner = self::ownerWithRole($form);

        return (bool) ($owner && self::isRater($owner));
    }

    /** The role named on the final review step. Defaults to QA. */
    public static function isRater(?User $user): bool
    {
        return $user && app(WorkflowSettings::class)->isRater($user->role);
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

    public static function actorMayMoveTo(User $user, string $type, string $to, ?string $from = null): bool
    {
        $rules = app(WorkflowSettings::class);

        if ($type === 'opcr') {
            $configured = match ($to) {
                'qa_approval' => $rules->opcr('creator_roles'),
                'approved'    => $rules->opcr('approver_roles'),
                'published',
                'draft'       => $rules->opcr('publisher_roles'),
                'qa_rating'   => $rules->opcr('rating_trigger_roles'),
                'rated'       => $rules->raterRoles(),
                'returned'    => $from === 'qa_rating'
                    ? $rules->raterRoles()
                    : $rules->opcr('approver_roles'),
                'final'       => $rules->raterRoles(),
                default       => null,
            };

            if ($configured !== null) {
                return in_array($user->role, $configured, true);
            }
        }

        if ($type === 'ipcr' && $to === 'returned' && $from === 'qa_rating') {
            return in_array($user->role, $rules->raterRoles(), true);
        }

        if ($type === 'ipcr' && $to === 'final') {
            return in_array($user->role, $rules->raterRoles(), true);
        }

        return in_array($user->role, self::ACTOR_ROLES[$type][$to] ?? [], true);
    }

    /**
     * The college OPCR can be sent for rating on any day of its school year.
     */
    public static function opcrRatingMonthOpen(PcrForm $form): bool
    {
        $form->loadMissing('schoolYear');
        $year = $form->schoolYear;

        if (! $year?->start_date || ! $year?->end_date) {
            return false;
        }

        $today = now()->startOfDay();

        return $today->greaterThanOrEqualTo($year->start_date->copy()->startOfDay())
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

    /**
     * A later school year can hold an empty form. Commitments and every later
     * step wait until that year is the current cycle and the period being
     * written is open. A year-wide OPCR waits until any period of that year
     * is open. Null when the write or the status move may proceed.
     */
    public static function cycleClosedMessage(PcrForm $form, ?int $explicit = null): ?string
    {
        $form->loadMissing('schoolYear');
        $year = $form->schoolYear;

        if (! $year?->is_active) {
            $label = $year?->label ?: 'This school year';

            return "{$label} is not the current cycle yet. Open the review period and set the year active before writing commitments or moving the form.";
        }

        $periodId = $explicit ?: ($form->rating_period_id ? (int) $form->rating_period_id : null);

        if ($periodId) {
            $period = RatingPeriod::find($periodId);

            return ($period && $period->status !== 'open') ? self::periodNotOpenMessage($period) : null;
        }

        $periods = RatingPeriod::where('school_year_id', $form->school_year_id)->orderBy('seq')->get();

        if ($periods->isEmpty()) {
            return null;
        }

        if ($form->type === 'opcr') {
            if ($periods->contains(fn (RatingPeriod $period) => $period->status === 'open')) {
                return null;
            }

            return self::periodNotOpenMessage(
                $periods->first(fn (RatingPeriod $period) => $period->status === 'upcoming') ?? $periods->first()
            );
        }

        $period = RatingPeriod::find(self::periodForWrite($form));

        return ($period && $period->status !== 'open') ? self::periodNotOpenMessage($period) : null;
    }

    private static function periodNotOpenMessage(RatingPeriod $period): string
    {
        if ($period->status === 'upcoming') {
            return "{$period->label} is still upcoming. Open it before writing commitments or moving the form.";
        }

        if ($period->status === 'closed') {
            return "{$period->label} is closed. Open it before writing commitments or moving the form.";
        }

        return "{$period->label} is not open. Open it before writing commitments or moving the form.";
    }

    /** Null when the write may proceed. A locked period is view-only. */
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
        if ($message = self::cycleClosedMessage($form, $explicit)) {
            return $message;
        }

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

    /** The review or rating step a form was last sent back from, if it was. */
    public static function reviewReturnStage(PcrForm $form): ?string
    {
        $logs = $form->relationLoaded('statusLogs')
            ? $form->statusLogs
            : PcrStatusLog::where('form_id', $form->id)->get();

        $from = $logs->where('to_status', 'returned')->sortByDesc('id')->first()?->from_status;

        return $from && self::isReviewDestination($from) ? $from : null;
    }

    public static function returnedFromRating(PcrForm $form): bool
    {
        return self::reviewReturnStage($form) === 'qa_rating';
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

    /**
     * President, QA, and admin — campus-wide form access and follow-ups.
     * Their My Team roster is still limited to offices they lead or watch;
     * campus follow-up is done from Forms → All.
     */
    public static function isCollegeMonitor(User $user): bool
    {
        return in_array($user->role, ['admin', 'president', 'qa'], true);
    }

    /**
     * May send a team follow-up: college monitors, named heads/VPs by role, or
     * anyone named on a review slot (dean, director, head) for an office.
     */
    public static function canFollowUp(User $user): bool
    {
        if (self::isCollegeMonitor($user) || in_array($user->role, ['program_head', 'vp'], true)) {
            return true;
        }

        return OrgUnit::query()->whereLedBy((int) $user->id)->exists();
    }

    /** May ask this person to start or finish their own IPCR. */
    public static function canFollowUpWith(User $actor, User $member): bool
    {
        if ((int) $actor->id === (int) $member->id) {
            return false;
        }

        if ($member->status !== 'active' || $member->role === 'admin') {
            return false;
        }

        if (self::isCollegeMonitor($actor)) {
            return true;
        }

        if (! $member->org_unit_id) {
            return false;
        }

        return in_array((int) $member->org_unit_id, OrgUnit::overseenIds((int) $actor->id), true);
    }

    public static function canView(User $user, PcrForm $form): bool
    {
        if (self::isCollegeMonitor($user)) {
            return true;
        }

        if ($form->type === 'opcr') {
            // Anyone can read a published college OPCR. Drafts stay with the people who already open them.
            if (self::opcrIsVisible($form)) {
                return true;
            }

            $rules = app(WorkflowSettings::class);

            if (in_array($user->role, $rules->opcr('approver_roles'), true)
                || in_array($user->role, $rules->opcr('rating_trigger_roles'), true)
                || $rules->isRater($user->role)
            ) {
                return true;
            }

            // VPs read the published college plan so they can cascade into
            // their IPCR. Drafts stay with the president until published.
            if ($user->role === 'vp' && self::opcrIsVisible($form)) {
                return true;
            }
        }

        if (self::owns($user, $form)) {
            return true;
        }

        // Named in the Accountable column. They read the college OPCR from
        // their own account; writing it stays with the president.
        if ($form->type === 'opcr' && self::isAccountableOn($user, $form)) {
            return true;
        }

        if ($form->commitment_approver_id && (int) $form->commitment_approver_id === (int) $user->id) {
            return true;
        }

        if (self::isReviewerFor($user, $form, $form->status)) {
            return true;
        }

        if (self::watchesForm($user, $form)) {
            return true;
        }

        // Anyone who leads an office (role or Hierarchy slot) reads the people
        // under them — not only accounts with role program_head / vp.
        $overseen = OrgUnit::overseenIds((int) $user->id);

        if ($overseen !== []) {
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
        }

        return false;
    }

    private static function isAccountableOn(User $user, PcrForm $form): bool
    {
        return PcrForm::query()->whereKey($form->id)->accountableTo((int) $user->id)->exists();
    }

    /** Named on Can view forms for this unit, or for a unit above it. Not a reviewer. */
    private static function watchesForm(User $user, PcrForm $form): bool
    {
        $ids = OrgUnit::watchedIds((int) $user->id);

        if ($ids === []) {
            return false;
        }

        if (in_array((int) $form->org_unit_id, $ids, true)) {
            return true;
        }

        if ($form->type !== 'ipcr' || ! $form->user_id) {
            return false;
        }

        $ownerUnitId = User::whereKey($form->user_id)->value('org_unit_id');

        return $ownerUnitId && in_array((int) $ownerUnitId, $ids, true);
    }

    /**
     * The head picks which attendance record backs a support line, and only
     * while that IPCR is on their rating step. Later reviewers read the percent.
     */
    public static function canLinkAttendance(User $user, PcrForm $form): bool
    {
        if ($form->type !== 'ipcr' || $form->status !== 'head_review') {
            return false;
        }

        if ((int) $form->head_reviewer_id !== (int) $user->id) {
            return false;
        }

        return self::formLockMessage($user, $form) === null;
    }

    public static function canEditCommitments(User $user, PcrForm $form): bool
    {
        if (self::cycleClosedMessage($form)) {
            return false;
        }

        if (! $form->isEditable() || ! self::owns($user, $form)) {
            return false;
        }

        // An OPCR sent back from rating keeps its targets. The writer fixes
        // the accomplishment and the evidence, then it returns to rating.
        if ($form->type === 'opcr' && $form->status === 'returned' && self::returnedFromRating($form)) {
            return false;
        }

        // Once commitments are approved they stay as approved, including when
        // the rating is sent back. Only a return from commitment review reopens them.
        return $form->type !== 'ipcr' || ! $form->commitment_approved_at;
    }

    /** Why a target edit was refused, when the reason is more specific than "not a draft". */
    public static function commitmentClosedMessage(PcrForm $form): ?string
    {
        if ($message = self::cycleClosedMessage($form)) {
            return $message;
        }

        if ($form->type === 'opcr' && $form->status === 'returned' && self::returnedFromRating($form)) {
            return 'This OPCR was returned from rating. Targets and success indicators stay as they are — update the accomplishment and the evidence.';
        }

        return null;
    }

    /**
     * Tasks stay closed until the plan is official. IPCR waits on commitment
     * approval; OPCR waits until targets are published (or returned from rating).
     */
    public static function mayRecordWork(PcrForm $form): bool
    {
        if ($form->type === 'opcr') {
            if (in_array($form->status, ['published', 'qa_rating'], true)) {
                return true;
            }

            return $form->status === 'returned' && self::returnedFromRating($form);
        }

        if ($form->type !== 'ipcr') {
            return true;
        }

        if ($form->commitment_approved_at) {
            return true;
        }

        return ! in_array($form->status, ['draft', 'returned', 'commitment_review'], true);
    }

    /**
     * Name accountable people while writing commitments (like OPCR), and keep
     * handing work out after approval until rating starts. Closed while the
     * form sits with an approver or is already in the rating chain.
     */
    public static function mayHandOut(PcrForm $form): bool
    {
        if ($form->type !== 'ipcr') {
            return true;
        }

        if (in_array($form->status, ['draft', 'returned'], true) && ! $form->commitment_approved_at) {
            return true;
        }

        return (bool) $form->commitment_approved_at
            && in_array($form->status, ['commitments_approved', 'returned'], true);
    }

    /**
     * Who approves this IPCR's commitments. QA and people named on the published
     * OPCR go to the president. That naming lasts the school year, so the second
     * period uses the same list — nobody is assigned again. Naming someone before
     * the OPCR is published does not send the form to their VP instead. A head of
     * units under two different VPs is approved by the president. Everyone else
     * goes to the person who handed them the work, then to the first active
     * reviewer above them. An empty slot is skipped, so a head with no VP is
     * approved by whoever fills the next stage — QA, when nobody sits in a unit
     * slot above them.
     */
    public static function commitmentApprover(PcrForm $form): ?User
    {
        $owner = self::ownerWithRole($form);

        if (! $owner) {
            return null;
        }

        if ($owner->role === 'qa' || self::accountableOnPublishedOpcr($form, $owner)) {
            return User::where('role', 'president')->where('status', 'active')->first();
        }

        if (self::namedOnOpcr($form, $owner)) {
            return null;
        }

        // A head under two VPs is not approved by either of them. The
        // president approves the commitments. Rating still goes to QA.
        if (self::headsUnitsUnderDifferentVps((int) $owner->id)) {
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
            $person = self::activeStageReviewer($form, $stage, (int) $owner->id);

            if ($person) {
                return $person;
            }
        }

        return null;
    }

    /**
     * The first active person who can fill this stage, other than the owner.
     * A unit slot still walks to the parent when it is empty. A role stage,
     * such as QA, is used only after every slot above the owner is empty.
     */
    private static function activeStageReviewer(PcrForm $form, array $stage, int $ownerId): ?User
    {
        $source = $stage['source'] ?? 'unit_slot';

        if ($source === 'unit_slot') {
            $id = $form->orgUnit?->reviewerIdForSlotInChain($stage['slot'] ?? '');
        } elseif ($source === 'role') {
            $id = User::query()
                ->where('role', $stage['role'] ?? '')
                ->where('status', 'active')
                ->where('id', '!=', $ownerId)
                ->orderBy('id')
                ->value('id');
        } else {
            return null;
        }

        if (! $id || (int) $id === $ownerId) {
            return null;
        }

        return User::whereKey($id)->where('status', 'active')->first();
    }

    /**
     * Why Send stays closed. Null when an approver can be chosen, or when the
     * form simply has nobody in the chain. Everyone — including QA and people
     * not named on the OPCR — waits until the college OPCR is published.
     */
    public static function commitmentWait(PcrForm $form): ?string
    {
        $owner = self::ownerWithRole($form);

        if (! $owner) {
            return null;
        }

        if (! self::collegeOpcrPublished($form)) {
            return 'The college OPCR is not published yet, so these commitments cannot be sent.';
        }

        // People named on an IPCR line may write right away, but they wait to
        // send until that assigner's commitments are approved.
        if ($assigner = self::unapprovedIpcrAssigner($form, $owner)) {
            $name = $assigner->owner?->name ?? 'your assigner';

            return "Waiting for {$name}'s commitments to be approved before these can be sent.";
        }

        return null;
    }

    /** This year's college OPCR is visible to the rest of the campus. */
    private static function collegeOpcrPublished(PcrForm $form): bool
    {
        return PcrForm::query()
            ->where('type', 'opcr')
            ->where('school_year_id', $form->school_year_id)
            ->whereIn('status', self::OPCR_VISIBLE)
            ->exists();
    }

    /**
     * The IPCR whose line was handed to this owner, when that form still has
     * unapproved commitments. OPCR handouts use the publish gate above instead.
     */
    private static function unapprovedIpcrAssigner(PcrForm $form, User $owner): ?PcrForm
    {
        $assignment = PcrTargetAssignment::query()
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
            ->with(['indicator.output.form.owner:id,name'])
            ->latest('id')
            ->first();

        $assignerForm = $assignment?->indicator?->output?->form;

        if (! $assignerForm || $assignerForm->commitment_approved_at) {
            return null;
        }

        return $assignerForm;
    }

    /** A short phrase for the send button: "the President" or the person's name. */
    public static function commitmentApproverLabel(?User $approver): ?string
    {
        if (! $approver) {
            return null;
        }

        return $approver->role === 'president' ? 'the President' : $approver->name;
    }

    /**
     * People named on a line write the narrative on their own commitment.
     * A blank one blocks submit on an IPCR and on the college OPCR.
     *
     * @return list<string>
     */
    public static function accountableNarrativeGaps(PcrForm $form): array
    {
        $periodId = $form->type === 'ipcr' && $form->rating_period_id
            ? (int) $form->rating_period_id
            : null;

        $lines = $form->indicators()
            ->whereNull('closed_at')
            ->with([
                'assignments.user:id,name',
                'openChildren.accomplishments',
                'openChildren.output.form.owner:id,name',
            ])
            ->get();

        $gaps = [];

        foreach ($lines as $line) {
            $people = [];

            foreach ($line->assignments as $assignment) {
                if ($periodId && $assignment->rating_period_id && (int) $assignment->rating_period_id !== $periodId) {
                    continue;
                }

                if (! $assignment->user) {
                    continue;
                }

                $people[(int) $assignment->user_id] = $assignment->user->name;
            }

            $children = $line->openChildren->filter(function ($child) use ($periodId) {
                if ($periodId && $child->rating_period_id && (int) $child->rating_period_id !== $periodId) {
                    return false;
                }

                return (bool) $child->output?->form?->owner;
            });

            foreach ($children as $child) {
                $owner = $child->output->form->owner;
                $people[(int) $owner->id] = $owner->name;
            }

            if ($people === []) {
                continue;
            }

            $target = self::lineLabel($line);

            foreach ($people as $userId => $name) {
                $theirs = $children->filter(
                    fn ($child) => (int) $child->output->form->owner->id === (int) $userId
                );

                if ($theirs->isEmpty()) {
                    $gaps[] = "{$name} still needs a narrative for “{$target}”.";

                    continue;
                }

                foreach ($theirs as $child) {
                    $records = $child->accomplishments;

                    if ($child->rating_period_id) {
                        $records = $records->where('rating_period_id', (int) $child->rating_period_id);
                    }

                    $hasNarrative = $records->contains(
                        fn ($record) => ! Html::isBlank($record->actual_accomplishment)
                    );

                    if ($hasNarrative) {
                        continue;
                    }

                    $label = self::lineLabel($child);
                    $gaps[] = "{$name} still needs a narrative for “" . ($label === 'A target' ? $target : $label) . "”.";
                }
            }
        }

        return $gaps;
    }

    public static function accountableNarrativeMessage(PcrForm $form): ?string
    {
        $gaps = self::accountableNarrativeGaps($form);

        if ($gaps === []) {
            return null;
        }

        $shown = array_slice($gaps, 0, 3);
        $rest  = count($gaps) - count($shown);
        $message = 'Finish the write-up before submitting. ' . implode(' ', $shown);

        if ($rest > 0) {
            $message .= ' ' . $rest . ' more line' . ($rest === 1 ? ' is' : 's are') . ' still incomplete.';
        }

        return $message;
    }

    private static function lineLabel(PcrIndicator $line): string
    {
        $text = trim(Html::toText($line->description));

        return $text === '' ? 'A target' : mb_strimwidth($text, 0, 60, '…');
    }

    /**
     * The sheet loads the owner with a short column list, which leaves role
     * empty. Drop that copy so the approver can be chosen from the real role.
     */
    private static function ownerWithRole(PcrForm $form): ?User
    {
        $form->loadMissing('orgUnit');

        if ($form->relationLoaded('owner') && $form->owner && ! array_key_exists('role', $form->owner->getAttributes())) {
            $form->unsetRelation('owner');
        }

        $form->loadMissing('owner');

        return $form->owner;
    }

    private static function accountableOnPublishedOpcr(PcrForm $form, User $owner): bool
    {
        return self::namedOnOpcr($form, $owner, true);
    }

    /**
     * Named on this year's OPCR. The assignment covers every review period of
     * that year, so a first-period naming still sends the second-period IPCR
     * to the president. Publish is optional.
     */
    private static function namedOnOpcr(PcrForm $form, User $owner, bool $publishedOnly = false): bool
    {
        return PcrTargetAssignment::query()
            ->where('user_id', $owner->id)
            ->whereHas('indicator.output.form', function ($query) use ($form, $publishedOnly) {
                $query->where('type', 'opcr')
                    ->where('school_year_id', $form->school_year_id)
                    ->when($publishedOnly, fn ($inner) => $inner->whereIn('status', self::OPCR_VISIBLE));
            })
            ->exists();
    }
}
