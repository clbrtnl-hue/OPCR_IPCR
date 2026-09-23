<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrComment;
use App\Models\PcrForm;
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
use App\Services\PcrAssignmentService;
use App\Services\PcrWorkflow;
use App\Services\RatingScale;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoYear2025Seeder extends Seeder
{
    private const LABEL = '2025';

    private array $units = [];

    private array $people = [];

    private SchoolYear $year;

    private RatingPeriod $first;

    private RatingPeriod $second;

    private PcrForm $opcr;

    /** The status each IPCR should end on, decided before any work is recorded. */
    private array $plan = [];

    public function run(): void
    {
        $organization = Organization::first();

        if (! $organization) {
            $this->command?->error('Seed the college first: php artisan migrate:fresh --seed');

            return;
        }

        app(CurrentOrganization::class)->set($organization->id);

        $this->units  = OrgUnit::get()->keyBy('code')->all();
        $this->people = User::get()->keyBy('email')->all();

        if (! isset($this->people['president@occ.edu.ph'], $this->units['OCC'])) {
            $this->command?->error('The base roster is missing. Run the DatabaseSeeder first.');

            return;
        }

        mt_srand(2025);

        $keepActive = SchoolYear::where('is_active', true)->where('label', '!=', self::LABEL)->first();

        $this->wipe();
        $this->cycle();
        $this->collegeOpcr();
        $this->delegate();
        $this->personalWork();
        $this->planStatuses();
        $this->recordProgress();
        $this->evidence();
        $this->rateAndClose();
        $this->remarks();
        $this->backdate();
        $this->audit();
        $this->bell();
        $this->profiles();

        if ($keepActive) {
            SchoolYear::where('id', $keepActive->id)->update(['is_active' => true]);
        }

        $this->report();
    }

    private function person(string $email): User
    {
        return $this->people[$email];
    }

    private function staff(): array
    {
        return array_values(array_filter(
            $this->people,
            fn ($user) => in_array($user->role, ['employee', 'program_head', 'vp'], true)
                && $user->status === 'active'
        ));
    }

    private function wipe(): void
    {
        $year = SchoolYear::where('label', self::LABEL)->first();

        if (! $year) {
            return;
        }

        $formIds     = PcrForm::where('school_year_id', $year->id)->pluck('id');
        $outputIds   = PcrOutput::whereIn('form_id', $formIds)->pluck('id');
        $indicatorIds = PcrIndicator::whereIn('output_id', $outputIds)->pluck('id');

        $accomplishmentIds = PcrAccomplishment::whereIn('indicator_id', $indicatorIds)->pluck('id');

        foreach (PcrAttachment::whereIn('accomplishment_id', $accomplishmentIds)->get() as $file) {
            @unlink(public_path('uploads/pcr/' . $file->file_path));
            $file->delete();
        }

        PcrRating::whereIn('indicator_id', $indicatorIds)->delete();
        PcrAccomplishment::whereIn('indicator_id', $indicatorIds)->delete();
        PcrIndicator::whereIn('id', $indicatorIds)->update(['parent_indicator_id' => null]);
        PcrIndicator::whereIn('id', $indicatorIds)->delete();
        PcrOutput::whereIn('id', $outputIds)->update(['parent_output_id' => null]);
        PcrOutput::whereIn('id', $outputIds)->delete();
        PcrPeriodSummary::whereIn('form_id', $formIds)->delete();
        PcrStatusLog::whereIn('form_id', $formIds)->delete();
        \Illuminate\Support\Facades\DB::table('pcr_comment_mentions')
            ->whereIn('comment_id', PcrComment::whereIn('form_id', $formIds)->pluck('id'))
            ->delete();
        PcrComment::whereIn('form_id', $formIds)->delete();
        Notification::whereIn('form_id', $formIds)->delete();
        ActivityLog::whereBetween('created_at', ['2025-01-01', '2025-12-31 23:59:59'])->delete();
        PcrForm::whereIn('id', $formIds)->delete();
        RatingPeriod::where('school_year_id', $year->id)->delete();
        $year->delete();

        $this->command?->info('Removed the previous 2025 demo data.');
    }

    private function cycle(): void
    {
        $this->year = SchoolYear::create([
            'label'      => self::LABEL,
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => 'closed',
            'is_active'  => false,
        ]);

        $this->first = RatingPeriod::create([
            'school_year_id' => $this->year->id,
            'seq'            => 1,
            'label'          => 'January to June',
            'opens_at'       => '2025-06-01',
            'closes_at'      => '2025-06-30',
            'status'         => 'closed',
            'is_active'      => false,
        ]);

        $this->second = RatingPeriod::create([
            'school_year_id' => $this->year->id,
            'seq'            => 2,
            'label'          => 'July to December',
            'opens_at'       => '2025-12-01',
            'closes_at'      => '2025-12-31',
            'status'         => 'closed',
            'is_active'      => false,
        ]);
    }

    private function collegeOpcr(): void
    {
        $president = $this->person('president@occ.edu.ph');
        $qa        = $this->person('qa@occ.edu.ph');

        $this->opcr = PcrForm::create([
            'type'           => 'opcr',
            'school_year_id' => $this->year->id,
            'org_unit_id'    => $this->units['OCC']->id,
            'status'         => 'draft',
        ]);

        $this->opcr->forceFill([
            'status'       => 'published',
            'submitted_at' => Carbon::parse('2025-01-15 09:20:00'),
        ])->save();

        $this->log($this->opcr, null, 'draft', 'Form created', $president, '2025-01-08 08:30:00');
        $this->log($this->opcr, 'draft', 'qa_approval', 'Targets sent to QA', $president, '2025-01-15 09:20:00');
        $this->log($this->opcr, 'qa_approval', 'approved', 'Targets approved', $qa, '2025-01-20 14:05:00');
        $this->log($this->opcr, 'approved', 'published', 'Published to the college', $president, '2025-01-22 08:00:00');

        $sheet = [
            ['strategic', 'Digitalization', 1200000, [
                'Roll out the online entrance examination to 100% of applicants from January to December 2025.',
                'Accommodate 75% of enrolees through the online enrolment system from January to December 2025.',
                'Achieve 60% participation of the student population in the online faculty evaluation for 2025.',
                'Migrate the grading system of all programmes to the online portal by December 2025.',
            ]],
            ['strategic', 'Employability Preparation', 350000, [
                '60% of the graduating class participate in the 2025 job fair hosted by OCC and its partners.',
                'Sign 5 new industry partners for the on-the-job training programme in 2025.',
            ]],
            ['strategic', 'Modernization', 900000, [
                'Accommodate 70% of the Information Technology students in the upgraded laboratories in 2025.',
                'Complete the library modernisation plan by December 2025.',
            ]],
            ['core', 'Instruction', 2400000, [
                'Increase enrolment by 5% in 2025 compared with the previous year.',
                'Increase student retention by 5% in 2025 compared with the previous year.',
                'Increase faculty retention by 5% in 2025 compared with the previous year.',
                'Apply for 1 additional programme offering within 2025.',
                'Achieve the national passing rate for board programmes in 2025.',
            ]],
            ['core', 'Research', 800000, [
                'Publish 20 peer-reviewed researches, journals or articles in 2025.',
                'Conduct 1 research colloquium in 2025.',
                'Submit 3 research proposals for external funding in 2025.',
            ]],
            ['core', 'Extension', 600000, [
                'Sign 2 MOU/MOA with local linkages in 2025.',
                'Adopt 2 communities for the extension programme in 2025.',
                'Deliver 4 community training activities in 2025.',
            ]],
            ['core', 'Production', 250000, [
                'Utilise 2 capstone or research projects adopted by an institution or establishment in 2025.',
            ]],
            ['support', 'Actions on matters referred by the LCE', null, [
                'Act on matters referred by the LCE within the reglementary period, with 100% accuracy.',
            ]],
            ['support', 'Compliance to regulatory orders', null, [
                'Comply with regulatory orders within the given period, with 100% accuracy.',
            ]],
            ['support', 'Committee membership', null, [
                'Attend the regular meetings of every committee membership held in 2025.',
            ]],
            ['support', 'Budget Utilization Rate', null, [
                'Meet the required budget utilisation rate for 2025.',
            ]],
        ];

        $order = 0;

        foreach ($sheet as [$section, $title, $budget, $lines]) {
            $output = PcrOutput::create([
                'form_id'    => $this->opcr->id,
                'section'    => $section,
                'title'      => $title,
                'sort_order' => ++$order,
            ]);

            foreach ($lines as $index => $description) {
                PcrIndicator::create([
                    'output_id'       => $output->id,
                    'description'     => "<p>{$description}</p>",
                    'target_date'     => $this->dateIn(3, 12),
                    'allotted_budget' => $budget ? round($budget / count($lines), 2) : null,
                    'sort_order'      => $index + 1,
                ]);
            }
        }
    }

    /** The office targets the president handed to the people who deliver them. */
    private function delegate(): void
    {
        $president   = $this->person('president@occ.edu.ph');
        $assignments = app(PcrAssignmentService::class);

        \Illuminate\Support\Facades\Auth::login($president);

        $byTitle = PcrOutput::where('form_id', $this->opcr->id)->get()->keyBy('title');

        $plan = [
            'Digitalization'             => ['registrar@occ.edu.ph', 'iyo@occ.edu.ph', 'verula@occ.edu.ph'],
            'Employability Preparation'  => ['puertos@occ.edu.ph', 'verula@occ.edu.ph'],
            'Modernization'              => ['bsit.head@occ.edu.ph', 'vacalares@occ.edu.ph'],
            'Instruction'                => ['bsit.head@occ.edu.ph', 'pacana@occ.edu.ph', 'magnetico@occ.edu.ph'],
            'Research'                   => ['research.head@occ.edu.ph', 'demetrio@occ.edu.ph'],
            'Extension'                  => ['extension.head@occ.edu.ph', 'vpial@occ.edu.ph'],
            'Production'                 => ['bsit.head@occ.edu.ph', 'research.head@occ.edu.ph'],
        ];

        foreach ($plan as $title => $emails) {
            $output = $byTitle[$title] ?? null;

            if (! $output) {
                continue;
            }

            foreach ($output->indicators as $index => $line) {
                $line->update(['target_date' => $this->dateIn(2, 6)]);

                // Every other target is shared, so the roll-up above it lands
                // on a real half-way figure rather than always 0 or 100.
                $take = $index % 2 === 0 ? 2 : 1;

                for ($slot = 0; $slot < $take; $slot++) {
                    $assignee = $this->person($emails[($index + $slot) % count($emails)]);
                    $assignments->assignIndicator($line, $assignee, $president);
                }
            }
        }
    }

    /** What each person committed to on their own account, in both periods. */
    private function personalWork(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $ownLines = [
            'strategic' => [
                'Complete the digital records clean-up for the office by the end of the period.',
                'Publish the office service standards on the college portal.',
            ],
            'core' => [
                'Deliver the full teaching or service load for the period with no unresolved complaints.',
                'Submit the office accomplishment report within 5 days of the period closing.',
                'Attend 2 professional development activities within the period.',
                'Complete the periodic evaluation of every service under the office.',
            ],
            'support' => [
                'Submit the daily time record within the first 3 working days of the following month.',
                'Attend all Management Committee meetings called within the period.',
                'Act on referred matters within the reglementary period.',
            ],
        ];

        foreach ($this->staff() as $person) {
            foreach ([$this->first, $this->second] as $period) {
                $form = $this->formFor($person, $period);
                $seq  = $period->seq;

                foreach ($ownLines as $section => $lines) {
                    $take = $section === 'strategic' ? 1 : ($section === 'core' ? 3 : 2);

                    $output = PcrOutput::firstOrCreate(
                        ['form_id' => $form->id, 'section' => $section, 'parent_output_id' => null, 'title' => $this->headingFor($section)],
                        ['sort_order' => $section === 'strategic' ? 1 : ($section === 'core' ? 2 : 3)]
                    );

                    foreach (array_slice($lines, 0, $take) as $index => $description) {
                        PcrIndicator::create([
                            'output_id'        => $output->id,
                            'rating_period_id' => $period->id,
                            'description'      => "<p>{$description}</p>",
                            'target_date'      => $seq === 1 ? $this->dateIn(2, 6) : $this->dateIn(8, 12),
                            'sort_order'       => 10 + $index,
                        ]);
                    }
                }
            }
        }
    }

    private function headingFor(string $section): string
    {
        return [
            'strategic' => 'Office Digitalization',
            'core'      => 'Core Functions',
            'support'   => 'Support Functions',
        ][$section];
    }

    private function formFor(User $person, RatingPeriod $period): PcrForm
    {
        $form = PcrForm::firstOrCreate(
            [
                'type'             => 'ipcr',
                'school_year_id'   => $this->year->id,
                'user_id'          => $person->id,
                'rating_period_id' => $period->id,
            ],
            [
                'org_unit_id' => $person->org_unit_id ?? $this->units['OCC']->id,
                'status'      => 'draft',
            ]
        );

        if ($form->wasRecentlyCreated) {
            $this->log($form, null, 'draft', 'Form opened', $person, $period->seq === 1 ? '2025-02-03 08:15:00' : '2025-08-05 08:15:00');
        }

        return $form;
    }

    /**
     * Some finished on time, some finished late, some are still running and a
     * few never started — the mix the reports are meant to surface.
     */
    private function recordProgress(): void
    {
        $progress = app(IndicatorProgressService::class);

        $leaves = PcrIndicator::with('output.form')
            ->whereHas('output.form', fn ($q) => $q->where('school_year_id', $this->year->id))
            ->whereDoesntHave('children')
            ->orderBy('id')
            ->get();

        foreach ($leaves as $line) {
            $form   = $line->output?->form;
            $roll   = ($line->id * 7) % 20;
            $due    = $line->target_date;
            $period = $line->rating_period_id ?? $form?->rating_period_id ?? $this->first->id;

            $closed = in_array($this->plan[$form?->id] ?? 'draft', ['rated', 'final'], true);
            $strong = $form && $form->id % 3 !== 0;

            $state = match (true) {
                $closed && $strong  => $roll < 16 ? 'completed' : 'ongoing',
                $closed && ! $strong => $roll < 10 ? 'completed' : ($roll < 16 ? 'ongoing' : 'not_started'),
                default             => match (true) {
                    $roll < 9  => 'completed',
                    $roll < 14 => 'ongoing',
                    $roll < 18 => 'not_started',
                    default    => 'deferred',
                },
            };

            if ($state === 'completed') {
                $late = $roll % 3 === 0;
                $done = $due
                    ? $due->copy()->addDays($late ? mt_rand(3, 25) : -mt_rand(0, 12))
                    : Carbon::parse('2025-11-30');

                $line->forceFill([
                    'progress_status' => 'completed',
                    'progress_pct'    => 100,
                    'completed_on'    => $done->min(Carbon::parse('2025-12-31'))->toDateString(),
                ])->save();

                PcrAccomplishment::create([
                    'indicator_id'          => $line->id,
                    'rating_period_id'      => $period,
                    'actual_accomplishment' => '<p>Delivered and documented. ' .
                        ($late ? 'Completed after the target date; the delay is noted in the remarks.' : 'Completed on or before the target date.') .
                        '</p>',
                ]);

                continue;
            }

            if ($state === 'ongoing') {
                $pct = $closed ? [60, 70, 80, 90, 75][$roll % 5] : [15, 30, 45, 60, 80][$roll % 5];

                $line->forceFill(['progress_status' => 'ongoing', 'progress_pct' => $pct])->save();

                PcrAccomplishment::create([
                    'indicator_id'          => $line->id,
                    'rating_period_id'      => $period,
                    'actual_accomplishment' => "<p>Partly delivered — {$pct}% of the target was met within the period.</p>",
                ]);

                continue;
            }

            if ($state === 'not_started') {
                $line->forceFill(['progress_status' => 'not_started', 'progress_pct' => 0])->save();

                continue;
            }

            $line->forceFill(['progress_status' => 'deferred', 'progress_pct' => mt_rand(5, 35)])->save();
        }

        foreach ($leaves as $line) {
            if ($line->parent_indicator_id || $line->output?->parent_output_id) {
                $progress->recalculateFrom($line->fresh());
            }
        }
    }

    /**
     * January to June is closed and rated; July to December is spread across the
     * chain so the pipeline has something in every stage.
     */
    private function planStatuses(): void
    {
        $secondHalf = [
            'final', 'final', 'final', 'rated', 'rated',
            'qa_rating', 'qa_rating', 'vp_review', 'vp_review',
            'head_review', 'returned', 'draft', 'draft',
        ];

        $index = 0;

        foreach (PcrForm::where('school_year_id', $this->year->id)->where('type', 'ipcr')->orderBy('id')->get() as $form) {
            $this->plan[$form->id] = (int) $form->rating_period_id === (int) $this->first->id
                ? 'final'
                : $secondHalf[$index++ % count($secondHalf)];
        }
    }

    private function rateAndClose(): void
    {
        $qa = $this->person('qa@occ.edu.ph');

        $forms = PcrForm::where('school_year_id', $this->year->id)
            ->where('type', 'ipcr')
            ->orderBy('id')
            ->get();

        foreach ($forms as $form) {
            $isFirst = (int) $form->rating_period_id === (int) $this->first->id;

            $this->walk($form, $this->plan[$form->id], $isFirst ? $this->first : $this->second, $qa);
        }

        $this->opcr->forceFill(['status' => 'published'])->save();
    }

    private function walk(PcrForm $form, string $status, RatingPeriod $period, User $qa): void
    {
        $owner     = User::find($form->user_id);
        $reviewers = PcrWorkflow::resolveReviewers($form);
        $head      = $reviewers['head_reviewer_id'] ? User::find($reviewers['head_reviewer_id']) : null;
        $vp        = $reviewers['vp_reviewer_id'] ? User::find($reviewers['vp_reviewer_id']) : null;
        $base      = Carbon::parse($period->seq === 1 ? '2025-06-05 09:00:00' : '2025-12-05 09:00:00');

        $form->forceFill([
            'head_reviewer_id' => $reviewers['head_reviewer_id'],
            'vp_reviewer_id'   => $reviewers['vp_reviewer_id'],
        ])->save();

        if ($status === 'draft') {
            return;
        }

        if ($status === 'returned') {
            $form->forceFill([
                'status'       => 'returned',
                'submitted_at' => $base,
            ])->save();

            $this->log($form, 'draft', 'head_review', 'Submitted for review', $owner, $base);
            $this->log($form, 'head_review', 'returned', 'The evidence attached does not match the stated target.', $head ?? $qa, $base->copy()->addDays(2));

            PcrComment::create([
                'form_id'     => $form->id,
                'stage'       => 'head_review',
                'body'        => '<p>Please attach the signed report for the second and third lines, then resubmit.</p>',
                'author_id'   => ($head ?? $qa)->id,
                'author_name' => ($head ?? $qa)->name,
                'author_role' => ($head ?? $qa)->role,
                'created_at'  => $base->copy()->addDays(2),
                'updated_at'  => $base->copy()->addDays(2),
            ]);

            return;
        }

        $form->forceFill(['status' => 'head_review', 'submitted_at' => $base])->save();
        $this->log($form, 'draft', 'head_review', 'Submitted for review', $owner, $base);

        if ($status === 'head_review') {
            return;
        }

        if ($head) {
            $form->forceFill([
                'reviewed_by'      => $head->id,
                'reviewed_by_name' => $head->name,
                'reviewed_at'      => $base->copy()->addDays(3),
                'status'           => 'vp_review',
            ])->save();

            $this->log($form, 'head_review', 'vp_review', 'Endorsed by the head', $head, $base->copy()->addDays(3));
        }

        if ($status === 'vp_review') {
            return;
        }

        if ($vp) {
            $form->forceFill([
                'vp_reviewed_by'      => $vp->id,
                'vp_reviewed_by_name' => $vp->name,
                'vp_reviewed_at'      => $base->copy()->addDays(6),
            ])->save();
        }

        $form->forceFill(['status' => 'qa_rating'])->save();
        $this->log($form, 'vp_review', 'qa_rating', 'Endorsed to QA', $vp ?? $head ?? $qa, $base->copy()->addDays(6));

        if ($status === 'qa_rating') {
            return;
        }

        $this->rate($form, $period, $qa, $base->copy()->addDays(12));

        if ($status === 'final') {
            $form->forceFill(['status' => 'final'])->save();
            $this->log($form, 'rated', 'final', 'Closed for the period', $qa, $base->copy()->addDays(20));
        }
    }

    private function rate(PcrForm $form, RatingPeriod $period, User $qa, Carbon $when): void
    {
        $lines = PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))
            ->with('output')
            ->get();

        $bySection = ['strategic' => [], 'core' => [], 'support' => []];

        foreach ($lines as $line) {
            [$q, $e, $t] = $this->scoreFor($line);
            $a = RatingScale::average($q, $e, $t);

            PcrRating::updateOrCreate(
                ['indicator_id' => $line->id, 'rating_period_id' => $period->id],
                [
                    'q' => $q, 'e' => $e, 't' => $t, 'a' => $a,
                    'remarks'  => $this->ratingRemark($line, $t),
                    'rated_by' => $qa->id, 'rated_by_name' => $qa->name,
                    'created_at' => $when, 'updated_at' => $when,
                ]
            );

            $bySection[$line->output->section][] = $a;
        }

        $sections = [
            'strategic' => RatingScale::mean($bySection['strategic']),
            'core'      => RatingScale::mean($bySection['core']),
            'support'   => RatingScale::mean($bySection['support']),
        ];

        $final = RatingScale::mean(array_values($sections));

        PcrPeriodSummary::updateOrCreate(
            ['form_id' => $form->id, 'rating_period_id' => $period->id],
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
            'rated_by'      => $qa->id,
            'rated_by_name' => $qa->name,
            'rated_at'      => $when,
        ])->save();

        $this->log($form, 'qa_rating', 'rated', 'Rated by QA', $qa, $when);
    }

    private function ratingRemark(PcrIndicator $line, int $timeliness): ?string
    {
        if ($line->progress_status !== 'completed') {
            return $line->progress_pct > 0
                ? 'Partly delivered within the period; the balance carries over.'
                : 'No accomplishment recorded against this target for the period.';
        }

        return $timeliness < 5
            ? 'Target met, but delivered after the committed date.'
            : 'Target met within the committed date, with evidence on file.';
    }

    /** What was delivered decides the score, so the numbers match the sheet. */
    private function scoreFor(PcrIndicator $line): array
    {
        $pct  = (int) $line->progress_pct;
        $late = $line->progress_status === 'completed'
            && $line->target_date
            && $line->completed_on
            && $line->completed_on->gt($line->target_date);

        $quality = $pct >= 100 ? 5 : ($pct >= 60 ? 4 : ($pct >= 30 ? 3 : 2));
        $effort  = max(2, min(5, $quality - ($pct >= 100 ? 0 : 1) + (mt_rand(0, 1))));
        $time    = $pct >= 100 ? ($late ? 3 : 5) : ($pct >= 60 ? 3 : 2);

        return [$quality, $effort, $time];
    }

    /** Evidence people attached to what they delivered — real files, opened from the tile. */
    private function evidence(): void
    {
        $folder = public_path('uploads/pcr');

        if (! is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        $kinds = [
            ['Certificate of completion', 'png', 'image/png'],
            ['Attendance sheet', 'jpg', 'image/jpeg'],
            ['Photo documentation', 'png', 'image/png'],
            ['Signed accomplishment report', 'jpg', 'image/jpeg'],
        ];

        $accomplishments = PcrAccomplishment::with('indicator.output.form.owner')
            ->whereHas('indicator.output.form', fn ($q) => $q->where('school_year_id', $this->year->id))
            ->get();

        foreach ($accomplishments as $index => $accomplishment) {
            $line = $accomplishment->indicator;

            if (! $line || $line->progress_status === 'not_started') {
                continue;
            }

            $owner = $line->output?->form?->owner ?? $this->person('president@occ.edu.ph');
            $files = $line->progress_status === 'completed' && $index % 3 === 0 ? 2 : 1;

            $accomplishment->update([
                'remarks' => $line->progress_status === 'completed'
                    ? 'Evidence filed with the office. Ready for QA verification.'
                    : 'Partial evidence attached; the balance follows next period.',
            ]);

            for ($slot = 0; $slot < $files; $slot++) {
                [$label, $extension, $mime] = $kinds[($index + $slot) % count($kinds)];

                $name = 'demo2025-' . $accomplishment->id . '-' . $slot . '.' . $extension;
                $size = $this->writeEvidence("{$folder}/{$name}", $extension, $label, $owner->name);

                PcrAttachment::create([
                    'accomplishment_id' => $accomplishment->id,
                    'file_path'         => $name,
                    'original_name'     => $label . '.' . $extension,
                    'mime'              => $mime,
                    'file_size'         => $size,
                    'uploaded_by'       => $owner->id,
                    'uploaded_by_name'  => $owner->name,
                    'created_at'        => $line->completed_on ?? Carbon::parse('2025-11-30'),
                    'updated_at'        => $line->completed_on ?? Carbon::parse('2025-11-30'),
                ]);
            }
        }
    }

    private function writeEvidence(string $path, string $extension, string $label, string $owner): int
    {
        $image = imagecreatetruecolor(720, 420);
        $paper = imagecolorallocate($image, 252, 252, 251);
        $ink   = imagecolorallocate($image, 30, 58, 114);
        $muted = imagecolorallocate($image, 120, 120, 120);

        imagefill($image, 0, 0, $paper);
        imagerectangle($image, 12, 12, 707, 407, $ink);
        imagestring($image, 5, 40, 60, 'OPOL COMMUNITY COLLEGE', $ink);
        imagestring($image, 4, 40, 100, $label, $ink);
        imagestring($image, 3, 40, 140, 'Filed by: ' . $owner, $muted);
        imagestring($image, 3, 40, 165, 'Performance cycle 2025', $muted);
        imagestring($image, 2, 40, 360, 'Demonstration document — OCC PMS sample data', $muted);

        $extension === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 82);
        imagedestroy($image);

        return (int) filesize($path);
    }

    /** The conversation around each form: the writer, the reviewers and QA. */
    private function remarks(): void
    {
        $qa = $this->person('qa@occ.edu.ph');

        $forms = PcrForm::with('owner', 'orgUnit')
            ->where('school_year_id', $this->year->id)
            ->where('status', '!=', 'draft')
            ->get();

        foreach ($forms as $index => $form) {
            $owner = $form->owner ?? $this->person('president@occ.edu.ph');
            $head  = $form->head_reviewer_id ? User::find($form->head_reviewer_id) : null;
            $vp    = $form->vp_reviewer_id ? User::find($form->vp_reviewer_id) : null;
            $when  = $form->submitted_at ?? Carbon::parse('2025-06-05 09:00:00');

            $this->remark($form, $owner, 'draft', '<p>Submitted for the period. The evidence for every completed line is attached to its row.</p>', $when->copy()->addMinutes(20));

            if ($head && in_array($form->status, ['vp_review', 'qa_rating', 'rated', 'final'], true)) {
                $this->remark(
                    $form,
                    $head,
                    'head_review',
                    '<p>Reviewed line by line. Targets and evidence agree; endorsing to the VP.</p>',
                    $when->copy()->addDays(3)->addHours(1),
                    $owner
                );
            }

            if ($vp && in_array($form->status, ['qa_rating', 'rated', 'final'], true) && $index % 2 === 0) {
                $this->remark(
                    $form,
                    $vp,
                    'vp_review',
                    '<p>Endorsed. Please keep the timeliness of the second-semester commitments in view.</p>',
                    $when->copy()->addDays(6)->addHours(2)
                );
            }

            if (in_array($form->status, ['rated', 'final'], true)) {
                $this->remark(
                    $form,
                    $qa,
                    'qa_rating',
                    $index % 3 === 0
                        ? '<p>Rated. Quality is strong; timeliness is pulled down by the lines delivered after their target date.</p>'
                        : '<p>Rated. Commitments are well evidenced and delivered within the period.</p>',
                    $when->copy()->addDays(12)->addHours(3),
                    $owner
                );
            }

            $line = PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))
                ->where('progress_status', '!=', 'completed')
                ->first();

            if ($line && $index % 4 === 0) {
                $this->remark(
                    $form,
                    $head ?? $qa,
                    $form->status,
                    '<p>This line is still short of its target — say what is outstanding in the accomplishment column.</p>',
                    $when->copy()->addDays(4),
                    $owner,
                    $line->id
                );
            }
        }
    }

    private function remark(PcrForm $form, User $author, string $unused, string $body, Carbon $when, ?User $mention = null, ?int $indicatorId = null): void
    {
        $stage = [
            'program_head' => 'head',
            'vp'           => 'vp',
            'qa'           => 'qa',
            'president'    => 'president',
            'employee'     => 'employee',
            'admin'        => 'qa',
        ][$author->role] ?? 'employee';

        $comment = PcrComment::create([
            'form_id'      => $form->id,
            'indicator_id' => $indicatorId,
            'stage'        => $stage,
            'body'         => $body,
            'author_id'    => $author->id,
            'author_name'  => $author->name,
            'author_role'  => $author->role,
            'created_at'   => $when,
            'updated_at'   => $when,
        ]);

        if ($mention && $mention->id !== $author->id) {
            $comment->mentions()->attach($mention->id);
        }
    }

    /**
     * Delegation and form creation happen as the seeder runs, so their rows
     * carry today's clock. A historical cycle has to read as one.
     */
    private function backdate(): void
    {
        $president = $this->person('president@occ.edu.ph');
        $formIds   = PcrForm::where('school_year_id', $this->year->id)->pluck('id');
        $opened    = Carbon::parse('2025-01-25 09:00:00');

        PcrStatusLog::whereIn('form_id', $formIds)
            ->where('note', 'Opened by an assignment')
            ->update([
                'created_at'        => $opened,
                'updated_at'        => $opened,
                'performed_by'      => $president->id,
                'performed_by_name' => $president->name,
            ]);

        foreach (PcrForm::whereIn('id', $formIds)->get() as $form) {
            $first = PcrStatusLog::where('form_id', $form->id)->min('created_at');
            $when  = $first ? Carbon::parse($first) : $opened;
            $touch = $form->rated_at ?? $form->submitted_at ?? $when;

            PcrForm::where('id', $form->id)->update(['created_at' => $when, 'updated_at' => $touch]);

            $outputIds = PcrOutput::where('form_id', $form->id)->pluck('id');

            PcrOutput::whereIn('id', $outputIds)->update(['created_at' => $when, 'updated_at' => $when]);
            PcrIndicator::whereIn('output_id', $outputIds)
                ->update(['created_at' => $when, 'updated_at' => $touch]);
        }
    }

    /** The audit trail for the year, so the admin page has a 2025 to look at. */
    private function audit(): void
    {
        $president = $this->person('president@occ.edu.ph');
        $admin     = $this->people['admin@occ.edu.ph'] ?? $president;
        $qa        = $this->person('qa@occ.edu.ph');

        $entries = [
            ['SchoolYear', $this->year->id, 'create', 'Opened the 2025 performance cycle', $admin, '2025-01-05 08:00:00'],
            ['RatingPeriod', $this->first->id, 'create', 'January to June opened for rating', $admin, '2025-06-01 08:00:00'],
            ['PcrForm', $this->opcr->id, 'publish', 'Published the 2025 college OPCR', $president, '2025-01-22 08:05:00'],
            ['RatingPeriod', $this->first->id, 'close', 'January to June closed', $admin, '2025-07-01 17:00:00'],
            ['RatingPeriod', $this->second->id, 'create', 'July to December opened for rating', $admin, '2025-12-01 08:00:00'],
            ['RatingPeriod', $this->second->id, 'close', 'July to December closed', $admin, '2026-01-05 17:00:00'],
            ['SchoolYear', $this->year->id, 'close', 'Closed the 2025 performance cycle', $admin, '2026-01-06 09:00:00'],
        ];

        foreach ($entries as [$type, $id, $action, $description, $actor, $when]) {
            ActivityLog::create([
                'subject_type' => $type,
                'subject_id'   => $id,
                'action'       => $action,
                'description'  => $description,
                'user_id'      => $actor->id,
                'user_name'    => $actor->name,
                'created_at'   => Carbon::parse($when),
                'updated_at'   => Carbon::parse($when),
            ]);
        }

        foreach (PcrForm::where('school_year_id', $this->year->id)->whereIn('status', ['rated', 'final'])->get() as $form) {
            ActivityLog::create([
                'subject_type' => 'PcrForm',
                'subject_id'   => $form->id,
                'action'       => 'rate',
                'description'  => 'Rated ' . strtoupper($form->type) . ' of ' . ($form->owner?->name ?? 'the college'),
                'user_id'      => $qa->id,
                'user_name'    => $qa->name,
                'created_at'   => $form->rated_at,
                'updated_at'   => $form->rated_at,
            ]);
        }
    }

    /** Notifications from a closed year belong in the list, already read. */
    private function bell(): void
    {
        $formIds = PcrForm::where('school_year_id', $this->year->id)->pluck('id');

        $president = $this->person('president@occ.edu.ph');

        Notification::whereIn('form_id', $formIds)->update([
            'read_at'    => Carbon::parse('2025-12-31 17:00:00'),
            'created_at' => Carbon::parse('2025-02-10 08:30:00'),
        ]);

        Notification::whereIn('form_id', $formIds)->whereNull('actor_id')->update([
            'actor_id'   => $president->id,
            'actor_name' => $president->name,
        ]);

        $qa = $this->person('qa@occ.edu.ph');

        foreach (PcrForm::whereIn('id', $formIds)->whereIn('status', ['rated', 'final'])->get() as $form) {
            if (! $form->user_id) {
                continue;
            }

            Notification::create([
                'user_id'    => $form->user_id,
                'type'       => 'rating',
                'title'      => 'Your IPCR has been rated',
                'body'       => 'QA released the rating for your ' . strtoupper($form->type) . '.',
                'form_id'    => $form->id,
                'actor_id'   => $qa->id,
                'actor_name' => $qa->name,
                'read_at'    => $form->rated_at,
                'created_at' => $form->rated_at,
                'updated_at' => $form->rated_at,
            ]);
        }
    }

    /** A Personal Data Sheet for everyone, so the person cards are not empty. */
    private function profiles(): void
    {
        foreach ($this->people as $person) {
            if ($person->role === 'admin' || UserProfile::where('user_id', $person->id)->exists()) {
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
                'user_id'        => $person->id,
                'level'          => 'college',
                'school'         => 'Mindanao State University — Iligan Institute of Technology',
                'degree'         => 'Bachelor of Science',
                'period_from'    => '1998',
                'period_to'      => '2002',
                'year_graduated' => '2002',
                'sort_order'     => 1,
            ]);

            UserEducation::create([
                'user_id'        => $person->id,
                'level'          => 'graduate',
                'school'         => 'Xavier University — Ateneo de Cagayan',
                'degree'         => 'Master of Arts in Education',
                'period_from'    => '2010',
                'period_to'      => '2013',
                'year_graduated' => '2013',
                'sort_order'     => 2,
            ]);

            UserEligibility::create([
                'user_id'           => $person->id,
                'eligibility'       => 'Career Service Professional',
                'rating'            => number_format(82 + ($seed % 12) + ($seed % 10) / 10, 2),
                'examination_date'  => Carbon::create(2005 + ($seed % 10), 3, 12)->toDateString(),
                'examination_place' => 'Cagayan de Oro City',
                'sort_order'        => 1,
            ]);

            UserTraining::create([
                'user_id'      => $person->id,
                'title'        => 'Results-Based Performance Management System Orientation',
                'started_on'   => '2025-02-10',
                'ended_on'     => '2025-02-12',
                'hours'        => 24,
                'kind'         => 'technical',
                'conducted_by' => 'Civil Service Commission — Region X',
                'sort_order'   => 1,
            ]);

            UserWorkExperience::create([
                'user_id'            => $person->id,
                'position'           => $person->position_title ?? 'Faculty',
                'company'            => 'Opol Community College',
                'started_on'         => Carbon::create(2015 + ($seed % 8), 6, 1)->toDateString(),
                'is_current'         => true,
                'monthly_salary'     => 35000 + ($seed % 10) * 2500,
                'salary_grade'       => (string) (11 + ($seed % 8)),
                'appointment_status' => 'Permanent',
                'is_government'      => true,
                'sort_order'         => 1,
            ]);

            UserVoluntaryWork::create([
                'user_id'      => $person->id,
                'organization' => 'Opol Community Extension Programme',
                'started_on'   => '2024-07-01',
                'ended_on'     => '2025-06-30',
                'hours'        => 40 + ($seed % 30),
                'position'     => 'Volunteer facilitator',
                'sort_order'   => 1,
            ]);
        }
    }

    private function log(PcrForm $form, ?string $from, string $to, string $note, ?User $actor, $when): void
    {
        PcrStatusLog::create([
            'form_id'           => $form->id,
            'from_status'       => $from,
            'to_status'         => $to,
            'note'              => $note,
            'performed_by'      => $actor?->id,
            'performed_by_name' => $actor?->name,
            'created_at'        => Carbon::parse($when),
            'updated_at'        => Carbon::parse($when),
        ]);
    }

    private function dateIn(int $fromMonth, int $toMonth): string
    {
        $month = mt_rand($fromMonth, $toMonth);

        return Carbon::create(2025, $month, mt_rand(1, 28))->toDateString();
    }

    private function report(): void
    {
        $forms = PcrForm::where('school_year_id', $this->year->id)->count();
        $lines = PcrIndicator::whereHas('output.form', fn ($q) => $q->where('school_year_id', $this->year->id))->count();
        $rated = PcrPeriodSummary::whereIn('form_id', PcrForm::where('school_year_id', $this->year->id)->pluck('id'))->count();

        $this->command?->info("2025 demo data: {$forms} forms, {$lines} commitments, {$rated} rated summaries.");
    }
}
