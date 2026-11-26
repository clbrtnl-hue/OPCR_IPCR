<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\PcrWorkflow;
use App\Services\RatingScale;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fills every office that has no active IPCR for 2026, so the reports and
 * the dashboard have progress on every unit. 2026 is left as the open year.
 * Safe to run again.
 * Accounts already on file keep their status, except the roster people the
 * Orientation PPT names (PPT_ROSTER), who are switched back on.
 *
 * New accounts sign in with "password". Their address is unit.{code}@occ.edu.ph.
 */
class PanelUnitCoverageSeeder extends Seeder
{
    private ?User $qa = null;

    /**
     * Roster accounts the Orientation PPT Final names. They were switched off
     * before; only these come back on, nobody else is touched.
     */
    private const PPT_ROSTER = [
        'iyo@occ.edu.ph',
        'comm@occ.edu.ph',
        'dean.cba@occ.edu.ph',
        'dean.cte@occ.edu.ph',
        'guidance@occ.edu.ph',
        'registrar@occ.edu.ph',
        'qaacad@occ.edu.ph',
        'vprpe@occ.edu.ph',
        'vpsas@occ.edu.ph',
        'vpial@occ.edu.ph',
        'vpaf@occ.edu.ph',
    ];

    /**
     * Named in the Orientation PPT Final for offices with no active filer.
     * [prefix, first, middle, last, suffix, credentials, position]
     *
     * @var array<string, array{0: ?string, 1: string, 2: ?string, 3: string, 4: ?string, 5: ?string, 6: string}>
     */
    private const STAFF = [
        'OP-PCO'      => ['Mr.', 'Aldwin Jhun', 'T.', 'Gutobat', null, null, 'Head, Promotion and Communication Office'],
        'AAS-TESDA'   => ['Ms.', 'Odette', 'T.', 'Galangke', null, null, 'Head for Vocational Education'],
        'SAS-SSS-CJP' => ['Mr.', 'John Rey', 'Y.', 'Puertos', null, null, 'Career and Job Placement Services'],
        'SAS-SSS-HLT' => [null, 'Ron Jake', 'I.', 'Romero', null, 'RN, LPT', 'Health Services'],
        'SAS-SSS-SFA' => ['Mr.', 'Jechris', 'O.', 'Villanosa', null, 'MBM', 'Scholarship and Financial Assistance Services'],
        'SAS-SEC'     => [null, 'Dante', null, 'Haglawan', null, null, 'Safety and Security Services'],
        'AFS-FIN-PAY' => ['Mr.', 'Ivan Christopher', null, 'Agan', null, null, 'Head of Payroll Management'],
        'AFS-AS-IMC'  => ['Mr.', 'John', 'A.', 'Cariaga', null, null, 'Head of Information and Maintenance Management'],
        'AFS-AS-SPP'  => ['Ms.', 'Rebecca', 'A.', 'Dilla', null, null, 'Head of Property and Supply Management'],
        'AFS-FIN-BUD' => ['Mr.', 'James', null, 'Torentira', null, null, 'Budget Assistant Officer'],
        'AFS-AS-PPM'  => ['Ms.', 'Merimel', 'E.', 'Mabao', null, null, 'Head of Physical Facilities Management'],
    ];

    public function run(): void
    {
        $organization = Organization::query()->first();

        if (! $organization) {
            $this->command?->error('Seed the college first.');

            return;
        }

        app(CurrentOrganization::class)->set($organization->id);

        $year = SchoolYear::where('label', '2026')->first();

        if (! $year) {
            $this->command?->error('There is no 2026 school year. Run the DatabaseSeeder first.');

            return;
        }

        $periods = RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->get();

        if ($periods->isEmpty()) {
            $this->command?->error("{$year->label} has no rating periods.");

            return;
        }

        User::whereIn('email', self::PPT_ROSTER)
            ->where('status', 'inactive')
            ->update(['status' => 'active']);

        $this->qa = User::where('role', 'qa')->where('status', 'active')->first()
            ?? User::where('role', 'admin')->where('status', 'active')->first();

        $filled  = 0;
        $created = 0;

        foreach (OrgUnit::orderBy('id')->get() as $unit) {
            $missing = $periods->filter(fn (RatingPeriod $period) => ! $this->covered($unit, $year, $period));

            if ($missing->isEmpty()) {
                continue;
            }

            $person = $this->filerFor($unit, $created);
            $this->ensureHead($unit, $person);

            foreach ($missing as $period) {
                $this->file($person, $unit, $year, $period);
                $filled++;
            }
        }

        SchoolYear::query()->where('id', '!=', $year->id)->update(['is_active' => false]);
        $year->forceFill(['is_active' => true, 'status' => 'open'])->save();

        if (! RatingPeriod::where('school_year_id', $year->id)->where('is_active', true)->exists()) {
            RatingPeriod::query()->update(['is_active' => false]);
            RatingPeriod::where('school_year_id', $year->id)->orderByDesc('seq')->first()
                ?->forceFill(['is_active' => true, 'status' => 'open'])->save();
        }

        $units = OrgUnit::count();

        $this->command?->info(
            "Panel coverage for {$year->label}: {$filled} forms across the offices that were empty. "
            . "{$created} new accounts. {$units} units on the chart."
        );
    }

    private function covered(OrgUnit $unit, SchoolYear $year, RatingPeriod $period): bool
    {
        return PcrForm::query()
            ->where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->where('rating_period_id', $period->id)
            ->where('org_unit_id', $unit->id)
            ->whereHas('owner', fn ($query) => $query->where('status', 'active'))
            ->whereHas('outputs.indicators')
            ->exists();
    }

    private function filerFor(OrgUnit $unit, int &$created): User
    {
        $existing = User::where('org_unit_id', $unit->id)
            ->where('status', 'active')
            ->whereIn('role', ['employee', 'program_head', 'vp', 'qa'])
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $email = 'unit.' . strtolower($unit->code) . '@occ.edu.ph';
        $found = User::where('email', $email)->first();

        if ($found) {
            $found->forceFill([
                'status'      => 'active',
                'org_unit_id' => $unit->id,
            ])->save();

            return $found;
        }

        // Offices the PPT does not name get a placeholder, labelled as such.
        [$prefix, $first, $middle, $last, $suffix, $credentials, $position] = self::STAFF[$unit->code] ?? [
            null,
            'Staff',
            null,
            $unit->code,
            null,
            null,
            'Staff, ' . $unit->name,
        ];

        $created++;

        return User::create([
            'prefix'         => $prefix,
            'first_name'     => $first,
            'middle_initial' => $middle,
            'last_name'      => $last,
            'suffix'         => $suffix,
            'credentials'    => $credentials,
            'email'          => $email,
            'password'       => 'password',
            'role'           => $unit->type === 'program' ? 'program_head' : 'employee',
            'status'         => 'active',
            'position_title' => $position,
            'org_unit_id'    => $unit->id,
        ]);
    }

    private function ensureHead(OrgUnit $unit, User $person): void
    {
        if ($unit->head_user_id || $unit->dean_user_id || $unit->reviewerIdForSlot('director_review')) {
            return;
        }

        $unit->update(['head_user_id' => $person->id]);
        $unit->unsetRelation('reviewers');
    }

    private function file(User $person, OrgUnit $unit, SchoolYear $year, RatingPeriod $period): void
    {
        $form = PcrForm::firstOrCreate(
            [
                'type'             => 'ipcr',
                'school_year_id'   => $year->id,
                'org_unit_id'      => $unit->id,
                'user_id'          => $person->id,
                'rating_period_id' => $period->id,
            ],
            ['status' => 'draft']
        );

        if ($form->outputs()->whereHas('indicators')->exists()) {
            return;
        }

        $profile = $this->profile((int) $unit->id);
        $lines   = [
            ['strategic', 'Office priority', "Deliver the priority service of {$unit->name} for this rating period and file the result."],
            ['core', 'Core functions', "Complete the regular services of {$unit->name} and keep the accomplishment on record."],
            ['core', 'Core functions', "Review the reports of {$unit->name} and close the items that are due this period."],
            ['support', 'Office support', "Submit the schedule, attendance, and office report of {$unit->name} on time."],
        ];

        $saved = [];

        foreach ($lines as $index => [$section, $title, $description]) {
            [$state, $pct] = $profile[$index];

            if ($period->status === 'closed' && $state === 'not_started') {
                $state = 'ongoing';
                $pct   = 35;
            }

            $output = PcrOutput::firstOrCreate(
                ['form_id' => $form->id, 'section' => $section, 'title' => $title],
                ['sort_order' => $section === 'strategic' ? 1 : ($section === 'core' ? 2 : 3)]
            );

            [$target, $completedOn] = $this->dates($state, (int) $unit->id + $index);

            $line = PcrIndicator::create([
                'output_id'                 => $output->id,
                'rating_period_id'          => $period->id,
                'description'               => '<p>' . e($description) . '</p>',
                'target_date'               => $target,
                'progress_status'           => $state,
                'progress_pct'              => $pct,
                'completed_on'              => $completedOn,
                'needs_commitment_approval' => false,
                'sort_order'                => $index + 1,
            ]);

            if ($state !== 'not_started') {
                $accomplishment = PcrAccomplishment::create([
                    'indicator_id'          => $line->id,
                    'rating_period_id'      => $period->id,
                    'actual_accomplishment' => $state === 'completed'
                        ? '<p>Delivered for ' . e($unit->name) . ' and filed with the office.</p>'
                        : '<p>Partly delivered for ' . e($unit->name) . '. The balance is still in progress.</p>',
                    'remarks' => $state === 'completed'
                        ? 'Evidence filed with the office.'
                        : 'Partial evidence; the balance follows.',
                ]);

                if ($state === 'completed' && $index % 2 === 0) {
                    $this->attachEvidence($accomplishment, $person);
                }
            }

            $saved[] = $line;
        }

        $submitted = Carbon::parse($year->start_date ?: now())
            ->addMonths((int) $unit->id % 6)
            ->addDays(2 + $period->seq)
            ->setTime(9, 15);

        $status = $this->statusFor($unit, $person, $period);
        $rated  = in_array($status, ['rated', 'final'], true);

        $reviewers = PcrWorkflow::resolveReviewers($form);

        $form->forceFill([
            'status'            => $status,
            'head_reviewer_id'  => $reviewers['head_reviewer_id'] ?? null,
            'dean_reviewer_id'  => $reviewers['dean_reviewer_id'] ?? null,
            'vp_reviewer_id'    => $reviewers['vp_reviewer_id'] ?? null,
            'submitted_at'      => $status === 'draft' ? null : $submitted,
            'rated_at'          => $rated ? $submitted->copy()->addDays(12) : null,
            'rated_by'          => $rated ? $this->qa?->id : null,
            'rated_by_name'     => $rated ? $this->qa?->name : null,
        ])->save();

        if ($status === 'commitments_approved' && $this->qa) {
            $form->forceFill([
                'commitment_approved_by'      => $this->qa->id,
                'commitment_approved_by_name' => $this->qa->name,
                'commitment_approved_at'      => $submitted->copy()->addDays(2),
            ])->save();
        }

        if ($rated) {
            $this->rate($form, $saved, (int) $unit->id % 3, $submitted->copy()->addDays(12));
        }

        PcrStatusLog::create([
            'form_id'           => $form->id,
            'from_status'       => null,
            'to_status'         => $status,
            'note'              => 'Demonstration record so this office shows on the reports.',
            'performed_by'      => $person->id,
            'performed_by_name' => $person->name,
            'created_at'        => $submitted,
            'updated_at'        => $submitted,
        ]);
    }

    /** @param  array<int, PcrIndicator>  $lines */
    private function rate(PcrForm $form, array $lines, int $bias, Carbon $when): void
    {
        $bySection = ['strategic' => [], 'core' => [], 'support' => []];

        foreach ($lines as $line) {
            $line->loadMissing('output');
            $pct  = (int) $line->progress_pct;
            $late = $line->progress_status === 'completed'
                && $line->target_date
                && $line->completed_on
                && $line->completed_on->gt($line->target_date);

            $quality = $pct >= 100 ? 5 : ($pct >= 60 ? 4 : ($pct >= 30 ? 3 : 2));
            $quality = max(2, min(5, $quality - $bias));
            $effort  = max(2, min(5, $quality - ($pct >= 100 ? 0 : 1)));
            $time    = $pct >= 100 ? ($late ? 3 : 5) : ($pct >= 30 ? 3 : 2);
            $average = RatingScale::average($quality, $effort, $time);

            PcrRating::updateOrCreate(
                ['indicator_id' => $line->id, 'rating_period_id' => $form->rating_period_id],
                [
                    'q'             => $quality,
                    'e'             => $effort,
                    't'             => $time,
                    'a'             => $average,
                    'remarks'       => $pct >= 100
                        ? ($late ? 'Target met, but delivered after the committed date.' : 'Target met within the committed date.')
                        : ($pct > 0 ? 'Partly delivered within the period.' : 'No accomplishment recorded for this target.'),
                    'rated_by'      => $this->qa?->id,
                    'rated_by_name' => $this->qa?->name,
                    'created_at'    => $when,
                    'updated_at'    => $when,
                ]
            );

            $section = $line->output->section ?? 'core';
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
                'rated_indicators'  => count($lines),
                'total_indicators'  => count($lines),
            ]
        );
    }

    private function statusFor(OrgUnit $unit, User $owner, RatingPeriod $period): string
    {
        if ($period->status === 'closed') {
            return ((int) $unit->id % 2 === 0) ? 'final' : 'rated';
        }

        $choices = ['final', 'rated', 'qa_rating', 'commitments_approved', 'returned', 'draft'];

        $head = $unit->reviewerIdForSlotInChain('head_user_id');

        if ($head && $head !== (int) $owner->id) {
            $choices[] = 'head_review';
        }

        $dean = $unit->reviewerIdForSlotInChain('dean_user_id');

        if ($dean && $dean !== (int) $owner->id) {
            $choices[] = 'dean_review';
        }

        $vp = $unit->reviewerIdForSlotInChain('vp_user_id');

        if ($vp && $vp !== (int) $owner->id) {
            $choices[] = 'vp_review';
        }

        return $choices[(int) $unit->id % count($choices)];
    }

    /** @return array<int, array{0: string, 1: int}> */
    private function profile(int $unitId): array
    {
        return [
            [
                ['completed', 100],
                ['completed', 100],
                ['completed', 100],
                ['ongoing', 80],
            ],
            [
                ['completed', 100],
                ['completed', 100],
                ['ongoing', 60],
                ['ongoing', 45],
            ],
            [
                ['completed', 100],
                ['ongoing', 55],
                ['ongoing', 30],
                ['not_started', 0],
            ],
            [
                ['ongoing', 40],
                ['ongoing', 25],
                ['not_started', 0],
                ['not_started', 0],
            ],
            [
                ['completed', 100],
                ['ongoing', 70],
                ['ongoing', 50],
                ['not_started', 0],
            ],
        ][$unitId % 5];
    }

    /** @return array{0: string, 1: ?string} */
    private function dates(string $state, int $seed): array
    {
        $today = now()->startOfDay();

        if ($state === 'completed') {
            $target = $today->copy()->subDays(25 + ($seed % 15));
            $late   = $seed % 4 === 0;
            $done   = $late ? $target->copy()->addDays(6) : $target->copy()->subDays(2);

            return [$target->toDateString(), $done->toDateString()];
        }

        $due = [
            $today->copy()->subDays(16),
            $today->copy()->addDays(3),
            $today->copy()->addDays(28),
        ][$seed % 3];

        return [$due->toDateString(), null];
    }

    private function attachEvidence(PcrAccomplishment $accomplishment, User $owner): void
    {
        $folder = public_path('uploads/pcr');

        if (! is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        $name = 'panel-unit-coverage.png';
        $path = $folder . DIRECTORY_SEPARATOR . $name;

        if (! is_file($path)) {
            $this->writeEvidence($path);
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
            'uploaded_by'       => $owner->id,
            'uploaded_by_name'  => $owner->name,
        ]);
    }

    private function writeEvidence(string $path): void
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
        imagestring($image, 3, 40, 160, 'Demonstration document for the panel reports', $muted);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
