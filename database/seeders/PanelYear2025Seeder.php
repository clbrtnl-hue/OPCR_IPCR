<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PcrAccomplishment;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\RatingScale;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A closed 2025 cycle where every office has an IPCR for both semesters,
 * every form is final, and every commitment has a Q, E, and T rating.
 * Re-running replaces only the 2025 year. The open year is left as it was.
 */
class PanelYear2025Seeder extends Seeder
{
    private const LABEL = '2025';

    private ?User $qa = null;

    public function run(): void
    {
        $organization = Organization::query()->first();

        if (! $organization) {
            $this->command?->error('Seed the college first.');

            return;
        }

        app(CurrentOrganization::class)->set($organization->id);

        $keepActive = SchoolYear::where('is_active', true)->where('label', '!=', self::LABEL)->value('id');

        $this->qa = User::where('role', 'qa')->where('status', 'active')->first()
            ?? User::where('role', 'admin')->where('status', 'active')->first();

        $this->wipe();

        $year = SchoolYear::create([
            'label'      => self::LABEL,
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => 'closed',
            'is_active'  => false,
        ]);

        $periods = [
            RatingPeriod::create([
                'school_year_id' => $year->id,
                'seq'            => 1,
                'label'          => 'January to June',
                'opens_at'       => '2025-01-01',
                'closes_at'      => '2025-06-30',
                'status'         => 'closed',
                'is_active'      => false,
            ]),
            RatingPeriod::create([
                'school_year_id' => $year->id,
                'seq'            => 2,
                'label'          => 'July to December',
                'opens_at'       => '2025-07-01',
                'closes_at'      => '2025-12-31',
                'status'         => 'closed',
                'is_active'      => false,
            ]),
        ];

        $forms = 0;

        foreach (OrgUnit::orderBy('id')->get() as $unit) {
            foreach ($this->filers($unit) as $person) {
                foreach ($periods as $period) {
                    $this->file($person, $unit, $year, $period);
                    $forms++;
                }
            }
        }

        if ($keepActive) {
            SchoolYear::whereKey($keepActive)->update(['is_active' => true]);
        }

        $this->command?->info("2025 closed cycle: {$forms} final forms, every line rated. The open year was left in place.");
    }

    /** @return array<int, User> */
    private function filers(OrgUnit $unit): array
    {
        $people = User::where('org_unit_id', $unit->id)
            ->where('status', 'active')
            ->whereIn('role', ['employee', 'program_head', 'vp', 'qa'])
            ->orderBy('id')
            ->get()
            ->all();

        if ($people !== []) {
            return $people;
        }

        $email = 'unit.' . strtolower($unit->code) . '@occ.edu.ph';
        $found = User::where('email', $email)->first();

        if ($found) {
            $found->forceFill(['status' => 'active', 'org_unit_id' => $unit->id])->save();

            return [$found];
        }

        return [User::create([
            'first_name'     => 'Staff',
            'last_name'      => $unit->code,
            'email'          => $email,
            'password'       => 'password',
            'role'           => $unit->type === 'program' ? 'program_head' : 'employee',
            'status'         => 'active',
            'position_title' => 'Staff, ' . $unit->name,
            'org_unit_id'    => $unit->id,
        ])];
    }

    private function file(User $person, OrgUnit $unit, SchoolYear $year, RatingPeriod $period): void
    {
        $form = PcrForm::create([
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $person->id,
            'rating_period_id' => $period->id,
            'status'           => 'draft',
        ]);

        $lines = [
            ['strategic', 'Office priority', "Deliver the priority service of {$unit->name} for this rating period and file the result."],
            ['core', 'Core functions', "Complete the regular services of {$unit->name} and keep the accomplishment on record."],
            ['core', 'Core functions', "Review the reports of {$unit->name} and close the items that are due this period."],
            ['support', 'Office support', "Submit the schedule, attendance, and office report of {$unit->name} on time."],
        ];

        $saved = [];
        $month = $period->seq === 1 ? 2 : 8;

        foreach ($lines as $index => [$section, $title, $description]) {
            [$state, $pct] = $this->line((int) $unit->id + $index);
            $target = Carbon::create(2025, $month + ($index % 3), 8 + (($unit->id + $index) % 18));
            $done = $state === 'completed' ? $target->copy()->subDays(2) : null;

            $output = PcrOutput::firstOrCreate(
                ['form_id' => $form->id, 'section' => $section, 'title' => $title],
                ['sort_order' => $section === 'strategic' ? 1 : ($section === 'core' ? 2 : 3)]
            );

            $line = PcrIndicator::create([
                'output_id'                 => $output->id,
                'rating_period_id'          => $period->id,
                'description'               => '<p>' . e($description) . '</p>',
                'target_date'               => $target->toDateString(),
                'progress_status'           => $state,
                'progress_pct'              => $pct,
                'completed_on'              => $done?->toDateString(),
                'needs_commitment_approval' => false,
                'sort_order'                => $index + 1,
            ]);

            if ($state !== 'not_started') {
                PcrAccomplishment::create([
                    'indicator_id'          => $line->id,
                    'rating_period_id'      => $period->id,
                    'actual_accomplishment' => $state === 'completed'
                        ? '<p>Delivered for ' . e($unit->name) . ' and filed before the period closed.</p>'
                        : '<p>Partly delivered for ' . e($unit->name) . ' by the close of the period.</p>',
                    'remarks' => 'Recorded for the closed 2025 cycle.',
                ]);
            }

            $saved[] = $line;
        }

        $submitted = Carbon::create(2025, $period->seq === 1 ? 3 : 9, 4 + ((int) $unit->id % 20), 9, 0, 0);
        $ratedAt = Carbon::create(2025, $period->seq === 1 ? 6 : 12, 12, 15, 0, 0);

        $form->forceFill([
            'status'        => 'final',
            'submitted_at'  => $submitted,
            'rated_at'      => $ratedAt,
            'rated_by'      => $this->qa?->id,
            'rated_by_name' => $this->qa?->name,
        ])->save();

        $this->rate($form, $saved, (int) $unit->id % 3, $ratedAt);

        PcrStatusLog::create([
            'form_id'           => $form->id,
            'from_status'       => 'rated',
            'to_status'         => 'final',
            'note'              => 'Closed for 2025. Every commitment was rated.',
            'performed_by'      => $this->qa?->id ?? $person->id,
            'performed_by_name' => $this->qa?->name ?? $person->name,
            'created_at'        => $ratedAt,
            'updated_at'        => $ratedAt,
        ]);
    }

    /** @param  array<int, PcrIndicator>  $lines */
    private function rate(PcrForm $form, array $lines, int $bias, Carbon $when): void
    {
        $bySection = ['strategic' => [], 'core' => [], 'support' => []];

        foreach ($lines as $line) {
            $line->loadMissing('output');
            $pct = (int) $line->progress_pct;
            $quality = $pct >= 100 ? 5 : ($pct >= 60 ? 4 : ($pct >= 30 ? 3 : 2));
            $quality = max(2, min(5, $quality - $bias));
            $effort = max(2, min(5, $quality - ($pct >= 100 ? 0 : 1)));
            $time = $pct >= 100 ? 5 : ($pct >= 30 ? 3 : 2);
            $average = RatingScale::average($quality, $effort, $time);

            PcrRating::create([
                'indicator_id'     => $line->id,
                'rating_period_id' => $form->rating_period_id,
                'q'                => $quality,
                'e'                => $effort,
                't'                => $time,
                'a'                => $average,
                'remarks'          => $pct >= 100
                    ? 'Target met within the committed date.'
                    : ($pct > 0 ? 'Partly delivered within the period.' : 'No accomplishment recorded for this target.'),
                'rated_by'         => $this->qa?->id,
                'rated_by_name'    => $this->qa?->name,
                'created_at'       => $when,
                'updated_at'       => $when,
            ]);

            $section = $line->output->section ?? 'core';
            $bySection[$section][] = $average;
        }

        $sections = [
            'strategic' => RatingScale::mean($bySection['strategic']),
            'core'      => RatingScale::mean($bySection['core']),
            'support'   => RatingScale::mean($bySection['support']),
        ];
        $final = RatingScale::mean(array_values($sections));

        PcrPeriodSummary::create([
            'form_id'           => $form->id,
            'rating_period_id'  => $form->rating_period_id,
            'strategic_average' => $sections['strategic'],
            'core_average'      => $sections['core'],
            'support_average'   => $sections['support'],
            'final_average'     => $final,
            'adjectival'        => RatingScale::adjectival($final),
            'rated_indicators'  => count($lines),
            'total_indicators'  => count($lines),
        ]);
    }

    /** @return array{0: string, 1: int} */
    private function line(int $seed): array
    {
        return [
            ['completed', 100],
            ['completed', 100],
            ['completed', 100],
            ['ongoing', 80],
            ['completed', 100],
            ['ongoing', 60],
            ['not_started', 0],
        ][$seed % 7];
    }

    private function wipe(): void
    {
        $year = SchoolYear::where('label', self::LABEL)->first();

        if (! $year) {
            return;
        }

        $formIds = PcrForm::where('school_year_id', $year->id)->pluck('id');
        $outputIds = DB::table('pcr_outputs')->whereIn('form_id', $formIds)->pluck('id');
        $indicatorIds = DB::table('pcr_indicators')->whereIn('output_id', $outputIds)->pluck('id');

        if (Schema::hasTable('pcr_target_replacements') && $indicatorIds->isNotEmpty()) {
            DB::table('pcr_target_replacements')->whereIn('old_indicator_id', $indicatorIds)->delete();
            DB::table('pcr_target_replacements')->whereIn('new_indicator_id', $indicatorIds)->update(['new_indicator_id' => null]);
        }

        DB::table('pcr_indicators')->whereIn('parent_indicator_id', $indicatorIds)->update(['parent_indicator_id' => null]);
        DB::table('pcr_indicators')->whereIn('replaces_indicator_id', $indicatorIds)->update(['replaces_indicator_id' => null]);

        PcrForm::whereIn('id', $formIds)->delete();
        RatingPeriod::where('school_year_id', $year->id)->delete();
        $year->delete();

        $this->command?->info('Removed the previous 2025 year.');
    }
}
