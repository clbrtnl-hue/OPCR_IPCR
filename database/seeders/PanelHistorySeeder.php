<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrComment;
use App\Models\PcrForm;
use App\Models\PcrFormReviewer;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserEligibility;
use App\Models\UserProfile;
use App\Models\UserTraining;
use App\Models\UserVoluntaryWork;
use App\Models\UserWorkExperience;
use App\Services\IndicatorProgressService;
use App\Services\PcrWorkflow;
use App\Services\RatingScale;
use App\Services\WorkflowSettings;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demonstration history for the 2026 cycle: commitments, progress, reviews,
 * ratings, evidence, remarks and an audit trail, so the dashboard and every
 * queue have something to show. Safe to run again. Work already on file is
 * left alone, except a few closed July–December forms are moved when a stage
 * (commitment approval, dean, director, QA) would otherwise be empty.
 */
class PanelHistorySeeder extends Seeder
{
    private const MARKER = 'Demonstration history for January to September 2026';

    private SchoolYear $year;

    private RatingPeriod $first;

    private RatingPeriod $second;

    private User $qa;

    private User $admin;

    public function run(): void
    {
        $organization = Organization::query()->first();

        if (! $organization) {
            $this->command?->error('Seed the college first.');

            return;
        }

        app(CurrentOrganization::class)->set($organization->id);

        $this->year = SchoolYear::where('label', '2026')->firstOrFail();
        $this->first = RatingPeriod::where('school_year_id', $this->year->id)->where('seq', 1)->firstOrFail();
        $this->second = RatingPeriod::where('school_year_id', $this->year->id)->where('seq', 2)->firstOrFail();
        $this->qa = User::where('email', 'qa.head@occ.edu.ph')->firstOrFail();
        $this->admin = User::where('role', 'admin')->firstOrFail();

        $this->ensureForms();
        $this->fillEmptyForms();
        $this->repairPlaceholders();
        $this->dateWhatIsAlreadyDone();
        $touched = $this->recordProgress();
        $touched = array_merge($touched, $this->balanceOpenDates());
        $this->advanceDrafts();
        $this->coverOpenSemester();
        $this->rollUp($touched);
        $this->profiles();
        $this->activateForTheDemo();

        $forms = PcrForm::where('school_year_id', $this->year->id)->count();
        $rated = PcrPeriodSummary::whereIn(
            'form_id',
            PcrForm::where('school_year_id', $this->year->id)->pluck('id')
        )->count();

        $this->command?->info("2026 demonstration history: {$forms} forms, {$rated} rated summaries.");
    }

    /** Everyone who files an IPCR has one for each semester. */
    private function ensureForms(): void
    {
        $people = User::whereIn('role', ['employee', 'program_head', 'vp', 'qa'])
            ->where('status', 'active')
            ->get();

        foreach ($people as $person) {
            foreach ([$this->first, $this->second] as $period) {
                $form = PcrForm::firstOrCreate(
                    [
                        'type'             => 'ipcr',
                        'school_year_id'   => $this->year->id,
                        'user_id'          => $person->id,
                        'rating_period_id' => $period->id,
                    ],
                    [
                        'org_unit_id' => $person->org_unit_id,
                        'status'      => 'draft',
                    ]
                );

                if (! $form->wasRecentlyCreated) {
                    continue;
                }

                $opened = Carbon::parse($period->seq === 1 ? '2026-01-12 08:30:00' : '2026-07-02 08:30:00');

                $form->forceFill(['created_at' => $opened, 'updated_at' => $opened])->save();

                PcrStatusLog::create([
                    'form_id'           => $form->id,
                    'from_status'       => null,
                    'to_status'         => 'draft',
                    'note'              => 'Form opened',
                    'performed_by'      => $person->id,
                    'performed_by_name' => $person->name,
                    'created_at'        => $opened,
                    'updated_at'        => $opened,
                ]);
            }
        }
    }

    private function fillEmptyForms(): void
    {
        $forms = PcrForm::with('owner.orgUnit')
            ->where('school_year_id', $this->year->id)
            ->where('type', 'ipcr')
            ->whereDoesntHave('outputs.indicators')
            ->get();

        foreach ($forms as $form) {
            $owner = $form->owner;
            $unit  = $owner?->orgUnit?->name ?? 'the office';
            $lines = [
                ['strategic', 'Office priorities', "Move one service of {$unit} online before the period closes."],
                ['core', 'Core functions', "Carry out the commitments of {$unit} for the rating period and file the accomplishment."],
                ['core', 'Core functions', "Complete the periodic review of services under {$unit} before the period closes."],
                ['support', 'Support functions', 'Submit the daily time record within five working days after each month.'],
                ['support', 'Support functions', 'Attend the scheduled office and college meetings for the period.'],
            ];

            $order = 0;

            foreach ($lines as [$section, $title, $description]) {
                $output = PcrOutput::firstOrCreate(
                    ['form_id' => $form->id, 'section' => $section, 'title' => $title],
                    ['sort_order' => $section === 'strategic' ? 1 : ($section === 'core' ? 2 : 3)]
                );

                PcrIndicator::create([
                    'output_id'        => $output->id,
                    'rating_period_id' => $form->rating_period_id,
                    'description'      => '<p>' . e($description) . '</p>',
                    'target_date'      => $this->targetFor($form, ++$order),
                    'sort_order'       => $order,
                ]);
            }
        }
    }

    /** Test scraps ("ok", "Untitled") read as real commitments on the reports. */
    private function repairPlaceholders(): void
    {
        $lines = PcrIndicator::with('output')
            ->whereHas('output.form', fn ($q) => $q->where('school_year_id', $this->year->id)->where('type', 'ipcr'))
            ->where('progress_status', 'not_started')
            ->whereDoesntHave('accomplishments')
            ->get();

        foreach ($lines as $line) {
            if (! $this->isJunk($line->description)) {
                continue;
            }

            $title = trim(preg_replace('/\s+/', ' ', (string) $line->output?->title) ?: 'This commitment');

            $line->update([
                'description' => '<p>' . e($title) . ' completed for the rating period, with the result documented and filed.</p>',
            ]);
        }
    }

    private function isJunk(?string $html): bool
    {
        $plain = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html))));

        if ($plain === '' || strlen($plain) < 24) {
            return true;
        }

        return in_array(strtolower($plain), ['new success indicator', 'untitled'], true);
    }

    /** Finished lines that never received a date still need one for the timeliness column. */
    private function dateWhatIsAlreadyDone(): void
    {
        $lines = PcrIndicator::with('output.form')
            ->whereHas('output.form', fn ($q) => $q->where('school_year_id', $this->year->id))
            ->where(fn ($q) => $q->whereNull('target_date')->orWhere(fn ($inner) => $inner
                ->where('progress_status', 'completed')
                ->whereNull('completed_on')))
            ->get();

        foreach ($lines as $line) {
            $form = $line->output?->form;

            if (! $line->target_date) {
                $line->target_date = $this->targetFor($form, $line->id);
            }

            if ($line->progress_status === 'completed' && ! $line->completed_on) {
                $line->completed_on = Carbon::parse($line->target_date)->subDays(2)->toDateString();
            }

            $line->save();
        }
    }

    /** @return array<int, PcrIndicator> */
    private function recordProgress(): array
    {
        $lines = PcrIndicator::with('output.form')
            ->whereHas('output.form', fn ($q) => $q->where('school_year_id', $this->year->id))
            ->where('progress_status', 'not_started')
            ->whereDoesntHave('accomplishments')
            ->whereDoesntHave('children')
            ->whereDoesntHave('assignments')
            ->get();

        $touched = [];

        foreach ($lines as $line) {
            $form  = $line->output?->form;
            $roll  = $line->id % 5;
            $state = match (true) {
                $roll <= 2 => 'completed',
                $roll === 3 => 'ongoing',
                default => 'not_started',
            };

            if (! $line->target_date) {
                $line->target_date = $this->targetFor($form, $line->id, $state);
            } elseif ($state !== 'completed') {
                $line->target_date = $this->targetFor($form, $line->id, $state);
            }

            if ($state === 'not_started') {
                $line->save();
                $touched[] = $line;

                continue;
            }

            $late = $state === 'completed' && $line->id % 3 === 0;
            $done = Carbon::parse($line->target_date)->addDays($late ? 8 : -3);

            $ongoingPct = [15, 45, 60, 80, 30][$line->id % 5];

            $line->forceFill([
                'progress_status' => $state === 'completed' ? 'completed' : 'ongoing',
                'progress_pct'    => $state === 'completed' ? 100 : $ongoingPct,
                'completed_on'    => $state === 'completed' ? $done->toDateString() : null,
            ])->save();

            $accomplishment = PcrAccomplishment::create([
                'indicator_id'          => $line->id,
                'rating_period_id'      => $line->rating_period_id ?: $form?->rating_period_id ?: $this->second->id,
                'actual_accomplishment' => $state === 'completed'
                    ? '<p>Delivered and documented for the rating period.'
                        . ($late ? ' Completed after the target date.' : ' Completed on or before the target date.')
                        . '</p>'
                    : '<p>Partly delivered. The balance is still in progress for this period.</p>',
                'remarks' => $state === 'completed'
                    ? 'Evidence filed with the office.'
                    : 'Partial evidence; the balance follows.',
            ]);

            if ($state === 'completed') {
                $this->attachEvidence($accomplishment, $form?->owner, $done);
            }

            $touched[] = $line;
        }

        return $touched;
    }

    private function advanceDrafts(): void
    {
        $forms = PcrForm::with('owner')
            ->where('school_year_id', $this->year->id)
            ->where('type', 'ipcr')
            ->where('status', 'draft')
            ->orderBy('id')
            ->get()
            ->reject(fn ($form) => $form->owner?->role === 'admin')
            ->values();

        $secondIndex = 0;

        foreach ($forms as $index => $form) {
            $isFirst = (int) $form->rating_period_id === (int) $this->first->id;

            if ($isFirst) {
                $month = [1, 2, 3, 4, 5, 6][$index % 6];
                $this->walk($form, 'final', Carbon::create(2026, $month, 6 + ($index % 10), 9, 15, 0));

                continue;
            }

            [$status, $when] = $this->secondHalfPlan($form, $secondIndex++);

            if ($status === 'draft' || ! $when) {
                continue;
            }

            $this->walk($form, $status, Carbon::parse($when));
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function secondHalfPlan(PcrForm $form, int $index): array
    {
        $named = [
            'vpial@occ.edu.ph'     => ['draft', null],
            'vpaa@occ.edu.ph'      => ['commitment_review', '2026-09-06 09:00:00'],
            'guidance@occ.edu.ph'  => ['vp_review', '2026-07-08 09:20:00'],
            'library@occ.edu.ph'   => ['qa_rating', '2026-07-22 10:05:00'],
            'registrar@occ.edu.ph' => ['returned', '2026-08-04 08:40:00'],
            'iyo@occ.edu.ph'       => ['final', '2026-07-16 09:00:00'],
            'cict1@occ.edu.ph'     => ['director_review', '2026-08-20 09:00:00'],
            'cict2@occ.edu.ph'     => ['commitments_approved', '2026-07-14 10:00:00'],
            'dean.cte@occ.edu.ph'  => ['rated', '2026-08-06 13:10:00'],
            'vpaf@occ.edu.ph'      => ['qa_rating', '2026-08-12 11:00:00'],
            'vprpe@occ.edu.ph'     => ['final', '2026-08-19 09:45:00'],
            'comm@occ.edu.ph'      => ['vp_review', '2026-09-03 08:30:00'],
            'faculty@occ.edu.ph'   => ['commitments_approved', '2026-07-28 15:00:00'],
            'faculty2@occ.edu.ph'  => ['head_review', '2026-09-02 09:00:00'],
            'faculty3@occ.edu.ph'  => ['dean_review', '2026-08-26 11:00:00'],
            'faculty4@occ.edu.ph'  => ['commitment_returned', '2026-08-04 08:40:00'],
            'pheadIT@occ.edu.ph'   => ['dean_review', '2026-09-01 10:15:00'],
            'hr.staff@occ.edu.ph'  => ['commitment_review', '2026-09-10 09:15:00'],
            'qa.head@occ.edu.ph'   => ['rated', '2026-08-11 10:20:00'],
            'vpsas@occ.edu.ph'     => ['vp_review', '2026-09-08 09:15:00'],
            'qaacad@occ.edu.ph'    => ['head_review', '2026-09-14 14:00:00'],
        ];

        $email = $form->owner?->email;

        if ($email && isset($named[$email])) {
            return $named[$email];
        }

        $cycle = [
            ['final', '2026-07-09 09:10:00'],
            ['rated', '2026-07-23 10:00:00'],
            ['qa_rating', '2026-08-06 09:30:00'],
            ['vp_review', '2026-08-18 14:00:00'],
            ['dean_review', '2026-08-25 09:00:00'],
            ['head_review', '2026-09-04 08:45:00'],
            ['director_review', '2026-09-08 10:00:00'],
            ['commitment_review', '2026-09-12 09:00:00'],
            ['commitments_approved', '2026-07-30 11:00:00'],
            ['returned', '2026-09-11 11:20:00'],
            ['commitment_returned', '2026-08-04 08:40:00'],
            ['draft', null],
        ];

        return $cycle[$index % count($cycle)];
    }

    private function walk(PcrForm $form, string $status, Carbon $submitted): void
    {
        $form->loadMissing('owner', 'orgUnit', 'ratingPeriod');

        $owner     = $form->owner;
        $reviewers = PcrWorkflow::resolveReviewers($form);

        $form->forceFill([
            'head_reviewer_id' => $reviewers['head_reviewer_id'] ?? null,
            'dean_reviewer_id' => $reviewers['dean_reviewer_id'] ?? null,
            'vp_reviewer_id'   => $reviewers['vp_reviewer_id'] ?? null,
        ])->save();

        $form->unsetRelation('stageReviewers');

        $commitmentReturn = $status === 'commitment_returned';
        $approver = PcrWorkflow::commitmentApprover($form);

        if (! $approver && ! in_array($status, ['commitment_review', 'commitments_approved'], true) && ! $commitmentReturn) {
            $approver = User::where('role', 'president')->where('status', 'active')->first();
        }

        if (in_array($status, ['commitment_review', 'commitments_approved'], true) || $commitmentReturn) {
            if (! $approver) {
                return;
            }

            $this->openCommitmentReview($form, $approver, $owner, $submitted);

            if ($status === 'commitment_review') {
                return;
            }

            if ($commitmentReturn) {
                $when = $submitted->copy()->addDays(2);

                $form->forceFill(['status' => 'returned'])->save();
                $this->log($form, 'commitment_review', 'returned', 'The commitments need a clearer target before they can be approved.', $approver, $when);
                $this->remark($form, $approver, 'Rewrite the second commitment so the target is measurable, then send it again.', $when);
                $this->bell($owner?->id, 'returned', 'Your commitments were returned for correction', 'The approver asked for a clearer target.', $form, $approver, $when);

                return;
            }

            $this->approveCommitments($form, $approver, $owner, $submitted->copy()->addDays(2));

            return;
        }

        if ($approver) {
            $this->openCommitmentReview($form, $approver, $owner, $submitted);
            $this->approveCommitments($form, $approver, $owner, $submitted->copy()->addDays(2));
        }

        $chain = $this->filledChain($form);

        if ($chain === []) {
            return;
        }

        $status = $this->reachable($chain, $status);
        $sent   = $submitted->copy()->addDays(4);
        [$firstStage, $firstPerson] = $chain[0];

        $form->forceFill([
            'status'       => $firstStage,
            'submitted_at' => $sent,
        ])->save();

        $this->log($form, 'commitments_approved', $firstStage, 'Submitted for review', $owner, $sent);
        $this->audit($form, 'submit', 'Submitted IPCR for ' . ($form->ratingPeriod?->label ?? 'the period'), $owner, $sent);
        $this->notifyStage($form, $firstStage, $firstPerson, $owner, $sent, true);

        if ($status === 'returned') {
            $this->returnFromReview($form, $firstStage, $firstPerson ?? $this->qa, $owner, $sent);

            return;
        }

        foreach ($chain as $index => [$stage, $person]) {
            if ($stage === 'qa_rating') {
                break;
            }

            if ($status === $stage) {
                $this->remark($form, $owner, 'Submitted for the period. Evidence for the completed lines is attached.', $sent->copy()->addMinutes(20));

                return;
            }

            $when = $sent->copy()->addDays(3 + ($index * 3));
            $next = $chain[$index + 1][0] ?? 'qa_rating';
            $nextPerson = $chain[$index + 1][1] ?? $this->qa;

            $this->signStage($form, $stage, $person, $when);
            $form->forceFill(['status' => $next])->save();
            $this->log($form, $stage, $next, 'Endorsed to the next reviewer', $person ?? $this->qa, $when);
            $this->remark($form, $person ?? $this->qa, 'Reviewed line by line. Endorsing to the next reviewer.', $when);
            $this->notifyStage($form, $next, $nextPerson, $person ?? $owner, $when, false);

            if ($status === $next) {
                return;
            }
        }

        if ($status === 'qa_rating') {
            return;
        }

        $ratedWhen = $sent->copy()->addDays(14);
        $this->rate($form, $ratedWhen);

        if ($status === 'final') {
            $closed = $sent->copy()->addDays(18);
            $form->forceFill(['status' => 'final'])->save();
            $this->log($form, 'rated', 'final', 'Closed for the period', $this->qa, $closed);
        }
    }

    private function openCommitmentReview(PcrForm $form, User $approver, ?User $owner, Carbon $when): void
    {
        $form->forceFill([
            'status'                 => 'commitment_review',
            'commitment_approver_id' => $approver->id,
            'submitted_at'           => $when,
        ])->save();

        $this->log($form, 'draft', 'commitment_review', 'Commitments sent for approval', $owner, $when);
        $this->bell(
            $approver->id,
            'approval',
            'IPCR commitments from ' . ($owner?->name ?? 'a staff member') . ' need your approval',
            'They are waiting in your review queue.',
            $form,
            $owner,
            $when
        );
    }

    private function approveCommitments(PcrForm $form, User $approver, ?User $owner, Carbon $when): void
    {
        $form->forceFill([
            'status'                       => 'commitments_approved',
            'commitment_approver_id'       => $approver->id,
            'commitment_approved_by'       => $approver->id,
            'commitment_approved_by_name'  => $approver->name,
            'commitment_approved_at'       => $when,
        ])->save();

        $this->log($form, 'commitment_review', 'commitments_approved', 'Commitments approved', $approver, $when);
        $this->bell(
            $owner?->id,
            'approved',
            'Your commitments were approved',
            'You can now hand work to your team and record what you delivered.',
            $form,
            $approver,
            $when
        );
    }

    /** @return array<int, array{0: string, 1: ?User}> */
    private function filledChain(PcrForm $form): array
    {
        $form->unsetRelation('stageReviewers');
        $chain = [];

        foreach (app(WorkflowSettings::class)->reviewStages() as $stage) {
            $name = $stage['status'] ?? '';

            if ($name === '') {
                continue;
            }

            if ($name === 'qa_rating') {
                $chain[] = ['qa_rating', $this->qa];

                continue;
            }

            $id = PcrWorkflow::reviewerId($form, $name);

            if ($id) {
                $chain[] = [$name, User::find($id)];
            }
        }

        return $chain;
    }

    /** @param  array<int, array{0: string, 1: ?User}>  $chain */
    private function reachable(array $chain, string $status): string
    {
        $stages = array_map(fn ($step) => $step[0], $chain);

        if (in_array($status, ['returned', 'rated', 'final'], true) || in_array($status, $stages, true)) {
            return $status;
        }

        return in_array('qa_rating', $stages, true) ? 'qa_rating' : ($stages[0] ?? 'draft');
    }

    private function notifyStage(PcrForm $form, string $stage, ?User $person, ?User $actor, Carbon $when, bool $first): void
    {
        $who = $form->owner?->name ?? 'a staff member';

        if ($stage === 'qa_rating') {
            $this->bell($this->qa->id, 'rating', "IPCR from {$who} is ready for rating", 'It has cleared the review chain.', $form, $actor, $when);

            return;
        }

        $this->bell(
            $person?->id,
            'review',
            "IPCR from {$who} needs your review",
            $first ? 'It is waiting in your review queue.' : 'The previous reviewer has endorsed it.',
            $form,
            $actor,
            $when
        );
    }

    private function returnFromReview(PcrForm $form, string $stage, User $reviewer, ?User $owner, Carbon $sent): void
    {
        $when = $sent->copy()->addDays(3);

        $form->forceFill(['status' => 'returned'])->save();
        $this->log($form, $stage, 'returned', 'The evidence does not yet match the stated target.', $reviewer, $when);
        $this->remark($form, $reviewer, 'Please attach the signed report for the open lines, then resubmit.', $when);
        $this->bell($owner?->id, 'review', 'Your IPCR was returned', 'The reviewer asked for a revision.', $form, $reviewer, $when);
    }

    private function signStage(PcrForm $form, string $stage, ?User $person, Carbon $when): void
    {
        if (! $person || $stage === 'qa_rating') {
            return;
        }

        $signed = [
            'reviewed_by'      => $person->id,
            'reviewed_by_name' => $person->name,
            'reviewed_at'      => $when,
        ];

        if ($stage === 'head_review') {
            $form->forceFill($signed)->save();

            return;
        }

        if ($stage === 'dean_review') {
            $form->forceFill([
                'dean_reviewed_by'      => $person->id,
                'dean_reviewed_by_name' => $person->name,
                'dean_reviewed_at'      => $when,
            ])->save();

            return;
        }

        if ($stage === 'vp_review') {
            $form->forceFill([
                'vp_reviewed_by'      => $person->id,
                'vp_reviewed_by_name' => $person->name,
                'vp_reviewed_at'      => $when,
            ])->save();

            return;
        }

        PcrFormReviewer::where('form_id', $form->id)->where('stage', $stage)->update($signed);
    }

    /**
     * July to December should show every queue. A stage with nobody in it
     * borrows a form whose reviewers can actually stop there.
     */
    private function coverOpenSemester(): void
    {
        $wanted = [
            'commitment_review',
            'commitments_approved',
            'head_review',
            'dean_review',
            'director_review',
            'qa_rating',
        ];

        $forms = PcrForm::with(['owner', 'orgUnit'])
            ->where('school_year_id', $this->year->id)
            ->where('type', 'ipcr')
            ->where('rating_period_id', $this->second->id)
            ->get();

        $counts = $forms->countBy('status');

        // Keep two rated-and-closed forms, and one form already sitting in each
        // stage, then borrow from whatever is left. Heads are often not on a
        // closed form, so the pool cannot be finals only.
        $protected = $forms->where('status', 'final')->sortBy('id')->take(2)->pluck('id');

        foreach ($wanted as $status) {
            $keeper = $forms->firstWhere('status', $status);

            if ($keeper) {
                $protected->push($keeper->id);
            }
        }

        $movable = $forms
            ->reject(fn (PcrForm $form) => $protected->contains($form->id))
            ->sortBy(fn (PcrForm $form) => match ($form->status) {
                'final', 'returned', 'vp_review', 'rated' => 0,
                default => 1,
            })
            ->values();

        $presidentIsWaiting = $forms->contains(fn (PcrForm $form) => $form->status === 'commitment_review'
            && User::whereKey($form->commitment_approver_id)->where('role', 'president')->exists());

        if (! $presidentIsWaiting) {
            $counts['commitment_review'] = 0;
        }

        foreach ($wanted as $status) {
            if (($counts[$status] ?? 0) > 0 || $movable->isEmpty()) {
                continue;
            }

            $candidates = $movable;

            if ($status === 'commitment_review') {
                $candidates = $movable->sortByDesc(function (PcrForm $form) {
                    $approver = PcrWorkflow::commitmentApprover($form);

                    return $approver && $approver->role === 'president' ? 1 : 0;
                })->values();
            }

            $form = $this->formThatCanStopAt($candidates, $status);

            if (! $form) {
                continue;
            }

            $this->rewind($form);
            $this->walk(
                $form->fresh(['owner', 'orgUnit', 'ratingPeriod']),
                $status,
                Carbon::parse('2026-08-18 09:00:00')
            );

            $movable = $movable->reject(fn (PcrForm $row) => $row->id === $form->id)->values();
            $counts[$status] = 1;
        }
    }

    /** @param  \Illuminate\Support\Collection<int, PcrForm>  $forms */
    private function formThatCanStopAt($forms, string $status): ?PcrForm
    {
        foreach ($forms as $form) {
            $form->loadMissing('owner', 'orgUnit');
            PcrWorkflow::resolveReviewers($form);
            $form->unsetRelation('stageReviewers');

            if (in_array($status, ['commitment_review', 'commitments_approved'], true)) {
                if (PcrWorkflow::commitmentApprover($form)) {
                    return $form;
                }

                continue;
            }

            if ($status === 'qa_rating') {
                return $form;
            }

            if (PcrWorkflow::reviewerId($form, $status)) {
                return $form;
            }
        }

        return null;
    }

    private function rewind(PcrForm $form): void
    {
        $indicatorIds = PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))->pluck('id');

        if (Schema::hasTable('pcr_return_marks')) {
            DB::table('pcr_return_marks')->where('form_id', $form->id)->delete();
        }

        PcrStatusLog::where('form_id', $form->id)->delete();

        $commentIds = PcrComment::where('form_id', $form->id)->pluck('id');

        if ($commentIds->isNotEmpty() && Schema::hasTable('pcr_comment_mentions')) {
            DB::table('pcr_comment_mentions')->whereIn('comment_id', $commentIds)->delete();
        }

        PcrComment::where('form_id', $form->id)->delete();
        Notification::where('form_id', $form->id)->delete();
        PcrRating::whereIn('indicator_id', $indicatorIds)->delete();
        PcrPeriodSummary::where('form_id', $form->id)->delete();
        ActivityLog::where('subject_type', 'PcrForm')->where('subject_id', $form->id)->delete();

        PcrFormReviewer::where('form_id', $form->id)->update([
            'reviewed_by'      => null,
            'reviewed_by_name' => null,
            'reviewed_at'      => null,
        ]);

        $form->forceFill([
            'status'                      => 'draft',
            'submitted_at'                => null,
            'rated_by'                    => null,
            'rated_by_name'               => null,
            'rated_at'                    => null,
            'commitment_approver_id'      => null,
            'commitment_approved_by'      => null,
            'commitment_approved_by_name' => null,
            'commitment_approved_at'      => null,
        ])->save();
    }

    /**
     * The open semester needs a mix: some lines already late, some due this
     * week, some still ahead, and ongoing work at more than one percentage.
     *
     * @return array<int, PcrIndicator>
     */
    private function balanceOpenDates(): array
    {
        $dates = $this->openSemesterDates();
        $pcts  = [15, 30, 45, 60, 80];

        $lines = PcrIndicator::with('output.form')
            ->whereHas('output.form', fn ($q) => $q
                ->where('school_year_id', $this->year->id)
                ->where('rating_period_id', $this->second->id))
            ->whereIn('progress_status', ['ongoing', 'not_started'])
            ->get();

        foreach ($lines as $line) {
            $slot = $line->id % 3;
            $due  = [$dates['overdue'], $dates['due_soon'], $dates['ahead']][$slot];

            $line->forceFill([
                'target_date'  => $due,
                'progress_pct' => $line->progress_status === 'ongoing' ? $pcts[intdiv((int) $line->id, 5) % 5] : 0,
            ])->save();
        }

        return $lines->all();
    }

    /** @return array{overdue: string, due_soon: string, ahead: string} */
    private function openSemesterDates(): array
    {
        $closes = Carbon::parse($this->second->closes_at ?? '2026-12-31')->startOfDay();
        $today  = now()->startOfDay();

        // Due dates follow the calendar, not the filing window. A target in
        // October is already late on a November afternoon even when the
        // semester still accepts submissions in December.
        $dueSoon = $today->copy()->addDays(3);
        $ahead   = $today->copy()->addDays(28);
        $overdue = $today->copy()->subDays(16);

        if ($dueSoon->gt($closes)) {
            $dueSoon = $closes->copy();
        }

        if ($ahead->gt($closes)) {
            $ahead = $closes->copy();
        }

        return [
            'overdue'  => $overdue->toDateString(),
            'due_soon' => $dueSoon->toDateString(),
            'ahead'    => $ahead->toDateString(),
        ];
    }

    private function rate(PcrForm $form, Carbon $when): void
    {
        $lines = PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))
            ->with('output')
            ->get();

        $bySection = ['strategic' => [], 'core' => [], 'support' => []];

        foreach ($lines as $line) {
            $pct  = (int) $line->progress_pct;
            $late = $line->progress_status === 'completed'
                && $line->target_date
                && $line->completed_on
                && $line->completed_on->gt($line->target_date);

            $quality = $pct >= 100 ? 5 : ($pct >= 60 ? 4 : ($pct >= 30 ? 3 : 2));
            $effort  = max(2, min(5, $quality - ($pct >= 100 ? 0 : 1)));
            $time    = $pct >= 100 ? ($late ? 3 : 5) : ($pct >= 30 ? 3 : 2);
            $average = RatingScale::average($quality, $effort, $time);

            PcrRating::updateOrCreate(
                ['indicator_id' => $line->id, 'rating_period_id' => $form->rating_period_id],
                [
                    'q' => $quality, 'e' => $effort, 't' => $time, 'a' => $average,
                    'remarks'    => $pct >= 100
                        ? ($late ? 'Target met, but delivered after the committed date.' : 'Target met within the committed date.')
                        : ($pct > 0 ? 'Partly delivered within the period.' : 'No accomplishment recorded for this target.'),
                    'rated_by'   => $this->qa->id,
                    'rated_by_name' => $this->qa->name,
                    'created_at' => $when,
                    'updated_at' => $when,
                ]
            );

            $section = $line->output->section ?? 'core';

            if (! isset($bySection[$section])) {
                $bySection[$section] = [];
            }

            $bySection[$section][] = $average;
        }

        $sections = [
            'strategic' => RatingScale::mean($bySection['strategic']),
            'core'      => RatingScale::mean($bySection['core']),
            'support'   => RatingScale::mean($bySection['support']),
        ];

        $final = RatingScale::mean(array_values($sections));

        PcrPeriodSummary::updateOrCreate(
            ['form_id' => $form->id, 'rating_period_id' => $form->rating_period_id],
            [
                'strategic_average' => $sections['strategic'],
                'core_average'      => $sections['core'],
                'support_average'   => $sections['support'],
                'final_average'     => $final,
                'adjectival'        => RatingScale::adjectival($final),
                'rated_indicators'  => $lines->count(),
                'total_indicators'  => $lines->count(),
            ]
        );

        $form->forceFill([
            'status'        => 'rated',
            'rated_by'      => $this->qa->id,
            'rated_by_name' => $this->qa->name,
            'rated_at'      => $when,
        ])->save();

        $this->log($form, 'qa_rating', 'rated', 'Rated by QA', $this->qa, $when);
        $this->audit($form, 'rate', 'Rated IPCR of ' . ($form->owner?->name ?? 'a staff member'), $this->qa, $when);
        $this->remark($form, $this->qa, 'Rated for the period. The score follows what was delivered and when.', $when);
        $this->bell(
            $form->user_id,
            'rating',
            'Your IPCR has been rated',
            'QA released the rating for this period.',
            $form,
            $this->qa,
            $when
        );
    }

    /** @param  array<int, PcrIndicator>  $lines */
    private function rollUp(array $lines): void
    {
        $progress = app(IndicatorProgressService::class);

        foreach ($lines as $line) {
            $fresh = $line->fresh();

            if ($fresh) {
                $progress->recalculateFrom($fresh);
            }
        }
    }

    private function targetFor(?PcrForm $form, int $seed, string $state = 'completed'): string
    {
        $second = $form && (int) $form->rating_period_id === (int) $this->second->id;

        if (! $second) {
            return Carbon::create(2026, 2 + ($seed % 5), 4 + ($seed % 20))->toDateString();
        }

        $dates = $this->openSemesterDates();

        if ($state === 'ongoing' || $state === 'not_started') {
            return [$dates['overdue'], $dates['due_soon'], $dates['ahead']][$seed % 3];
        }

        return Carbon::create(2026, 7 + ($seed % 3), 8 + ($seed % 18))->toDateString();
    }

    private function attachEvidence(PcrAccomplishment $accomplishment, ?User $owner, Carbon $when): void
    {
        $folder = public_path('uploads/pcr');

        if (! is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        $name = 'history2026-' . $accomplishment->id . '.png';
        $path = $folder . DIRECTORY_SEPARATOR . $name;

        if (! is_file($path)) {
            $this->writeEvidence($path, $owner?->name ?? 'Opol Community College');
        }

        if (PcrAttachment::where('accomplishment_id', $accomplishment->id)->exists()) {
            return;
        }

        PcrAttachment::create([
            'accomplishment_id' => $accomplishment->id,
            'file_path'         => $name,
            'original_name'     => 'Signed accomplishment.png',
            'mime'              => 'image/png',
            'file_size'         => (int) (@filesize($path) ?: 0),
            'uploaded_by'       => $owner?->id,
            'uploaded_by_name'  => $owner?->name,
            'created_at'        => $when,
            'updated_at'        => $when,
        ]);
    }

    private function writeEvidence(string $path, string $owner): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            file_put_contents($path, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            ));

            return;
        }

        $image = imagecreatetruecolor(720, 420);
        $paper = imagecolorallocate($image, 252, 252, 251);
        $ink   = imagecolorallocate($image, 30, 58, 114);
        $muted = imagecolorallocate($image, 120, 120, 120);

        imagefill($image, 0, 0, $paper);
        imagerectangle($image, 12, 12, 707, 407, $ink);
        imagestring($image, 5, 40, 60, 'OPOL COMMUNITY COLLEGE', $ink);
        imagestring($image, 4, 40, 100, 'Signed accomplishment', $ink);
        imagestring($image, 3, 40, 140, 'Filed by: ' . $owner, $muted);
        imagestring($image, 3, 40, 165, 'Performance cycle 2026', $muted);
        imagestring($image, 2, 40, 360, 'Demonstration document — OCC PMS sample data', $muted);
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function remark(PcrForm $form, ?User $author, string $body, Carbon $when): void
    {
        if (! $author) {
            return;
        }

        $stage = [
            'program_head' => 'head',
            'vp'           => 'vp',
            'qa'           => 'qa',
            'president'    => 'president',
            'employee'     => 'employee',
            'admin'        => 'qa',
        ][$author->role] ?? 'employee';

        PcrComment::create([
            'form_id'     => $form->id,
            'stage'       => $stage,
            'body'        => '<p>' . e($body) . '</p>',
            'author_id'   => $author->id,
            'author_name' => $author->name,
            'author_role' => $author->role,
            'created_at'  => $when,
            'updated_at'  => $when,
        ]);
    }

    private function log(PcrForm $form, ?string $from, string $to, string $note, ?User $actor, Carbon $when): void
    {
        PcrStatusLog::create([
            'form_id'           => $form->id,
            'from_status'       => $from,
            'to_status'         => $to,
            'note'              => $note,
            'performed_by'      => $actor?->id,
            'performed_by_name' => $actor?->name,
            'created_at'        => $when,
            'updated_at'        => $when,
        ]);
    }

    private function audit(PcrForm $form, string $action, string $description, ?User $actor, Carbon $when): void
    {
        ActivityLog::create([
            'subject_type' => 'PcrForm',
            'subject_id'   => $form->id,
            'action'       => $action,
            'description'  => $description,
            'user_id'      => $actor?->id,
            'user_name'    => $actor?->name,
            'created_at'   => $when,
            'updated_at'   => $when,
        ]);
    }

    private function bell(?int $userId, string $type, string $title, string $body, PcrForm $form, ?User $actor, Carbon $when): void
    {
        if (! $userId || $userId === $actor?->id) {
            return;
        }

        Notification::create([
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'form_id'    => $form->id,
            'actor_id'   => $actor?->id,
            'actor_name' => $actor?->name,
            'read_at'    => $when->lt(Carbon::parse('2026-09-01')) ? $when->copy()->addDay() : null,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    private function profiles(): void
    {
        foreach (User::where('role', '!=', 'admin')->get() as $person) {
            if (UserProfile::where('user_id', $person->id)->exists()) {
                continue;
            }

            $seed = $person->id;

            UserProfile::create([
                'user_id'             => $person->id,
                'date_of_birth'       => Carbon::create(1975 + ($seed % 18), 1 + ($seed % 12), 1 + ($seed % 27))->toDateString(),
                'place_of_birth'      => 'Cagayan de Oro City',
                'sex'                 => $seed % 2 === 0 ? 'female' : 'male',
                'civil_status'        => $seed % 3 === 0 ? 'single' : 'married',
                'citizenship'         => 'Filipino',
                'height_m'            => 1.55 + ($seed % 20) / 100,
                'weight_kg'           => 52 + ($seed % 25),
                'blood_type'          => ['O+', 'A+', 'B+', 'AB+'][$seed % 4],
                'gsis_id'             => '0000-0000-' . str_pad((string) $seed, 4, '0', STR_PAD_LEFT),
                'pagibig_id'          => '0000-0000-' . str_pad((string) ($seed * 3), 4, '0', STR_PAD_LEFT),
                'philhealth_id'       => '00-000000000-' . ($seed % 10),
                'sss_id'              => '00-0000000-' . ($seed % 10),
                'tin'                 => '000-000-000-' . str_pad((string) $seed, 3, '0', STR_PAD_LEFT),
                'agency_employee_no'  => 'OCC-' . str_pad((string) $seed, 4, '0', STR_PAD_LEFT),
                'residential_address' => 'Poblacion, Opol, Misamis Oriental',
                'permanent_address'   => 'Poblacion, Opol, Misamis Oriental',
                'mobile'              => '09' . str_pad((string) (170000000 + $seed * 137), 9, '0', STR_PAD_LEFT),
            ]);

            UserEducation::create([
                'user_id' => $person->id, 'level' => 'college',
                'school' => 'Mindanao State University — Iligan Institute of Technology',
                'degree' => 'Bachelor of Science', 'period_from' => '1998', 'period_to' => '2002',
                'year_graduated' => '2002', 'sort_order' => 1,
            ]);

            UserEligibility::create([
                'user_id' => $person->id, 'eligibility' => 'Career Service Professional',
                'rating' => number_format(82 + ($seed % 12) + ($seed % 10) / 10, 2),
                'examination_date' => Carbon::create(2005 + ($seed % 10), 3, 12)->toDateString(),
                'examination_place' => 'Cagayan de Oro City', 'sort_order' => 1,
            ]);

            UserTraining::create([
                'user_id' => $person->id,
                'title' => 'Results-Based Performance Management System Orientation',
                'started_on' => '2026-02-10', 'ended_on' => '2026-02-12', 'hours' => 24,
                'kind' => 'technical', 'conducted_by' => 'Civil Service Commission — Region X',
                'sort_order' => 1,
            ]);

            UserWorkExperience::create([
                'user_id' => $person->id, 'position' => $person->position_title ?? 'Faculty',
                'company' => 'Opol Community College',
                'started_on' => Carbon::create(2015 + ($seed % 8), 6, 1)->toDateString(),
                'is_current' => true, 'monthly_salary' => 35000 + ($seed % 10) * 2500,
                'salary_grade' => (string) (11 + ($seed % 8)), 'appointment_status' => 'Permanent',
                'is_government' => true, 'sort_order' => 1,
            ]);

            UserVoluntaryWork::create([
                'user_id' => $person->id, 'organization' => 'Opol Community Extension Programme',
                'started_on' => '2025-07-01', 'ended_on' => '2026-06-30',
                'hours' => 40 + ($seed % 30), 'position' => 'Volunteer facilitator', 'sort_order' => 1,
            ]);
        }
    }

    /**
     * Reports and the dashboard open on the active year. 2026 is the year
     * with the history, and July to December is the semester in progress.
     */
    private function activateForTheDemo(): void
    {
        SchoolYear::query()->update(['is_active' => false]);
        SchoolYear::whereKey($this->year->id)->update(['is_active' => true, 'status' => 'open']);

        RatingPeriod::query()->update(['is_active' => false]);
        RatingPeriod::whereKey($this->first->id)->update(['status' => 'closed', 'is_active' => false]);
        RatingPeriod::whereKey($this->second->id)->update([
            'status'         => 'open',
            'is_active'      => true,
            'is_locked'      => false,
            'locked_at'      => null,
            'locked_by'      => null,
            'locked_by_name' => null,
        ]);

        if (ActivityLog::where('description', self::MARKER)->exists()) {
            return;
        }

        ActivityLog::create([
            'subject_type' => 'SchoolYear',
            'subject_id'   => $this->year->id,
            'action'       => 'update',
            'description'  => self::MARKER,
            'user_id'      => $this->admin->id,
            'user_name'    => $this->admin->name,
            'created_at'   => Carbon::parse('2026-09-15 08:00:00'),
            'updated_at'   => Carbon::parse('2026-09-15 08:00:00'),
        ]);
    }
}
