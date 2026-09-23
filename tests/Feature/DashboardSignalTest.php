<?php

namespace Tests\Feature;

use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\User;
use App\Services\WorkflowSettings;
use Tests\PmsTestCase;

class DashboardSignalTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit(['name' => 'BS Information Technology', 'code' => 'BSIT']);
        $year = $this->makeSchoolYear(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $period = $this->makePeriod($year, 1);
        $period->update(['opens_at' => '2026-01-01', 'closes_at' => '2026-06-30']);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $head      = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $vp        = User::factory()->create(['role' => 'vp']);
        $staff     = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $unit->update(['head_user_id' => $head->id, 'vp_user_id' => $vp->id]);

        return compact('unit', 'year', 'period', 'president', 'head', 'vp', 'staff');
    }

    private function form(array $context, array $attributes = []): PcrForm
    {
        return $this->makeForm(array_merge([
            'type'             => 'ipcr',
            'org_unit_id'      => $context['unit']->id,
            'school_year_id'   => $context['year']->id,
            'rating_period_id' => $context['period']->id,
            'user_id'          => User::factory()->create([
                'role' => 'employee', 'org_unit_id' => $context['unit']->id,
            ])->id,
            'status'           => 'rated',
        ], $attributes));
    }

    private function line(PcrForm $form, array $attributes = []): PcrIndicator
    {
        $output = PcrOutput::firstOrCreate(
            ['form_id' => $form->id, 'section' => 'core'],
            ['title' => 'Instruction']
        );

        return PcrIndicator::create(array_merge([
            'output_id'        => $output->id,
            'rating_period_id' => $form->rating_period_id,
            'description'      => '<p>A commitment.</p>',
            'progress_status'  => 'completed',
            'progress_pct'     => 100,
        ], $attributes));
    }

    private function board(array $context): array
    {
        $this->actingAsUser($context['president']);

        return $this->getJson("/api/reports/summary?school_year_id={$context['year']->id}")
            ->assertSuccessful()
            ->json();
    }

    private function stage(array $data, string $key): array
    {
        return collect($data['turnaround']['stages'])->firstWhere('key', $key);
    }

    // ---- Turnaround -----------------------------------------------------

    public function test_it_measures_how_long_each_stage_took(): void
    {
        $context = $this->college();

        foreach ([[2, 4, 9], [4, 8, 15], [6, 10, 21]] as [$head, $vp, $rated]) {
            $this->form($context, [
                'submitted_at'     => '2026-03-01 09:00:00',
                'reviewed_at'      => now()->parse('2026-03-01 09:00:00')->addDays($head),
                'vp_reviewed_at'   => now()->parse('2026-03-01 09:00:00')->addDays($vp),
                'rated_at'         => now()->parse('2026-03-01 09:00:00')->addDays($rated),
                'head_reviewer_id' => $context['head']->id,
                'vp_reviewer_id'   => $context['vp']->id,
            ]);
        }

        $data = $this->board($context);

        $this->assertEquals(4.0, $this->stage($data, 'submitted_to_head')['median_days']);
        $this->assertEquals(4.0, $this->stage($data, 'head_to_vp')['median_days']);
        $this->assertEquals(15.0, $this->stage($data, 'end_to_end')['median_days']);
        $this->assertSame(3, $this->stage($data, 'end_to_end')['forms']);
        $this->assertSame('vp_to_qa', $data['turnaround']['slowest_stage']);
    }

    public function test_an_even_number_of_forms_averages_the_two_middle_values(): void
    {
        $context = $this->college();

        foreach ([2, 4, 6, 12] as $days) {
            $this->form($context, [
                'submitted_at'     => '2026-03-01 09:00:00',
                'reviewed_at'      => now()->parse('2026-03-01 09:00:00')->addDays($days),
                'head_reviewer_id' => $context['head']->id,
            ]);
        }

        $data = $this->board($context);

        $this->assertEquals(5.0, $this->stage($data, 'submitted_to_head')['median_days']);
        $this->assertEquals(6.0, $this->stage($data, 'submitted_to_head')['mean_days']);
    }

    public function test_a_skipped_head_folds_into_one_gap(): void
    {
        $context = $this->college();

        $this->form($context, [
            'submitted_at'   => '2026-03-01 09:00:00',
            'reviewed_at'    => null,
            'vp_reviewed_at' => '2026-03-06 09:00:00',
            'vp_reviewer_id' => $context['vp']->id,
        ]);

        $data = $this->board($context);

        $this->assertSame(0, $this->stage($data, 'submitted_to_head')['forms']);
        $this->assertEquals(5.0, $this->stage($data, 'head_to_vp')['median_days']);
    }

    public function test_a_stage_nobody_held_is_counted_as_skipped_not_as_speed(): void
    {
        $context = $this->college();

        $this->form($context, [
            'submitted_at'   => '2026-03-01 09:00:00',
            'vp_reviewed_at' => '2026-03-01 09:00:00',
            'vp_reviewer_id' => null,
            'rated_at'       => '2026-03-10 09:00:00',
        ]);

        $data = $this->board($context);

        $this->assertSame(0, $this->stage($data, 'head_to_vp')['forms']);
        $this->assertSame(1, $this->stage($data, 'head_to_vp')['skipped']);
        $this->assertNull($this->stage($data, 'head_to_vp')['median_days']);

        $this->assertEmpty(
            collect($data['turnaround']['reviewers'])->where('stage', 'vp')->all(),
            'A stage nobody held must not be charged to a reviewer.'
        );
    }

    public function test_a_form_still_in_review_is_reported_as_waiting(): void
    {
        $context = $this->college();

        $this->form($context, [
            'status'           => 'head_review',
            'submitted_at'     => now()->subDays(19),
            'head_reviewer_id' => $context['head']->id,
        ]);

        $data = $this->board($context);

        $waiting = collect($data['turnaround']['waiting'])->firstWhere('status', 'head_review');

        $this->assertSame(1, $waiting['forms']);
        $this->assertEqualsWithDelta(19, $waiting['oldest_days'], 0.1);

        $reviewer = collect($data['turnaround']['reviewers'])->firstWhere('id', $context['head']->id);

        $this->assertSame(1, $reviewer['pending']);
        $this->assertSame($context['head']->name, $reviewer['name']);
    }

    // ---- Q/E/T ----------------------------------------------------------

    private function rate(PcrIndicator $line, int $q, int $e, int $t): void
    {
        PcrRating::create([
            'indicator_id'     => $line->id,
            'rating_period_id' => $line->rating_period_id,
            'q' => $q, 'e' => $e, 't' => $t,
            'a' => round(($q + $e + $t) / 3, 2),
        ]);
    }

    public function test_it_profiles_the_three_dimensions(): void
    {
        $context = $this->college();
        $form    = $this->form($context);

        $this->rate($this->line($form), 5, 4, 2);
        $this->rate($this->line($form), 5, 4, 4);

        $data = $this->board($context);

        $this->assertSame(['q', 'e', 't'], $data['dimensions']['keys']);
        $this->assertSame(['min' => 1, 'max' => 5], $data['dimensions']['bounds']);
        $this->assertSame(2, $data['dimensions']['rated_lines']);

        $college = collect($data['dimensions']['college'])->keyBy('key');

        $this->assertEquals(5.0, $college['q']['mean']);
        $this->assertEquals(4.0, $college['e']['mean']);
        $this->assertEquals(3.0, $college['t']['mean']);
        $this->assertSame('Timeliness', $college['t']['label']);

        $unit = $data['dimensions']['units'][0];

        $this->assertSame($context['unit']->id, $unit['id']);
        $this->assertSame('t', $unit['weakest']);
        $this->assertEquals(2.0, $unit['spread']);
    }

    public function test_the_profile_follows_the_configured_instrument(): void
    {
        $context = $this->college();
        $form    = $this->form($context);

        $this->rate($this->line($form), 5, 4, 2);

        app(WorkflowSettings::class)->put('rating', [
            'dimensions' => ['q', 't'],
            'min'        => 1,
            'max'        => 4,
            'bands'      => [['min' => 0.0, 'label' => 'Poor', 'value' => 1]],
        ]);

        $data = $this->board($context);

        $this->assertSame(['q', 't'], $data['dimensions']['keys']);
        $this->assertSame(['min' => 1, 'max' => 4], $data['dimensions']['bounds']);

        $keys = collect($data['dimensions']['college'])->pluck('key')->all();

        $this->assertNotContains('e', $keys);
        $this->assertArrayNotHasKey('e', $data['dimensions']['units'][0]['scores']);
    }

    public function test_a_configured_dimension_with_no_column_is_dropped(): void
    {
        $context = $this->college();
        $form    = $this->form($context);

        $this->rate($this->line($form), 5, 4, 2);

        app(WorkflowSettings::class)->put('rating', [
            'dimensions' => ['q', 'made_up'],
            'min'        => 1,
            'max'        => 5,
            'bands'      => [['min' => 0.0, 'label' => 'Poor', 'value' => 1]],
        ]);

        $data = $this->board($context);

        $this->assertSame(['q'], $data['dimensions']['keys']);
    }

    // ---- Evidence -------------------------------------------------------

    private function accomplish(PcrIndicator $line, ?string $text, int $files = 0): void
    {
        $record = PcrAccomplishment::create([
            'indicator_id'          => $line->id,
            'rating_period_id'      => $line->rating_period_id,
            'actual_accomplishment' => $text,
        ]);

        foreach (range(1, max($files, 0)) as $index) {
            if ($files === 0) {
                break;
            }

            PcrAttachment::create([
                'accomplishment_id' => $record->id,
                'file_path'         => "evidence-{$record->id}-{$index}.png",
                'original_name'     => 'evidence.png',
                'mime'              => 'image/png',
                'file_size'         => 1024,
            ]);
        }
    }

    public function test_it_finds_completed_work_with_no_evidence_or_no_narrative(): void
    {
        $context = $this->college();
        $form    = $this->form($context);

        $this->accomplish($this->line($form), '<p>Delivered in full.</p>', 1);
        $this->accomplish($this->line($form), '<p><br></p>', 1);
        $this->accomplish($this->line($form), '<p>Delivered, no file.</p>', 0);
        $this->line($form);
        $this->line($form, ['progress_status' => 'ongoing', 'progress_pct' => 30]);

        $data = $this->board($context);

        $this->assertSame(4, $data['evidence']['completed']);
        $this->assertSame(1, $data['evidence']['compliant']);
        $this->assertSame(25, $data['evidence']['pct']);
        $this->assertSame(2, $data['evidence']['missing_evidence']);
        $this->assertSame(2, $data['evidence']['blank_narrative']);
        $this->assertSame(1, $data['evidence']['no_accomplishment']);
        $this->assertSame(2, $data['evidence']['attachments']);
        $this->assertCount(3, $data['evidence']['gaps']);

        $unit = $data['evidence']['units'][0];

        $this->assertSame($context['unit']->id, $unit['id']);
        $this->assertSame(4, $unit['completed']);
        $this->assertSame(1, $unit['compliant']);
    }

    // ---- Deadlines ------------------------------------------------------

    public function test_a_form_filed_after_the_period_closes_is_counted_late(): void
    {
        $context = $this->college();

        $this->form($context, ['submitted_at' => '2026-07-14 09:00:00']);
        $this->form($context, ['submitted_at' => '2026-06-30 23:00:00']);
        $this->form($context, [
            'type' => 'opcr', 'user_id' => null, 'rating_period_id' => null,
            'submitted_at' => '2026-07-20 09:00:00',
        ]);

        $data = $this->board($context);

        $this->assertSame(1, $data['deadlines']['late']['forms']);
        $this->assertSame(2, $data['deadlines']['late']['judged']);
        $this->assertSame(1, $data['deadlines']['late']['unjudged']);
        $this->assertSame(14, $data['deadlines']['late_forms'][0]['days_late']);

        $this->assertSame('2026-06-30', $data['deadlines']['active']['closes_at']);
        $this->assertSame('closed', $data['deadlines']['active']['state']);
    }

    public function test_a_year_with_no_period_dates_reports_nothing_late(): void
    {
        $context = $this->college();
        $context['period']->update(['opens_at' => null, 'closes_at' => null]);

        $this->form($context, ['submitted_at' => '2026-07-14 09:00:00']);

        $data = $this->board($context);

        $this->assertSame(0, $data['deadlines']['late']['forms']);
        $this->assertSame(1, $data['deadlines']['late']['unjudged']);
        $this->assertNull($data['deadlines']['active']['closes_at']);
    }

    // ---- The bug fixes --------------------------------------------------

    public function test_a_form_with_two_period_summaries_reads_the_later_one(): void
    {
        $context = $this->college();
        $second  = $this->makePeriod($context['year'], 2);
        $form    = $this->form($context);

        PcrPeriodSummary::create([
            'form_id' => $form->id, 'rating_period_id' => $context['period']->id,
            'final_average' => 3.0, 'adjectival' => 'Satisfactory',
        ]);

        PcrPeriodSummary::create([
            'form_id' => $form->id, 'rating_period_id' => $second->id,
            'final_average' => 4.6, 'adjectival' => 'Very Satisfactory',
        ]);

        $this->assertEquals(4.6, $this->board($context)['totals']['college_average']);
    }

    public function test_a_zero_average_is_counted_not_dropped(): void
    {
        $context = $this->college();
        $form    = $this->form($context);

        PcrPeriodSummary::create([
            'form_id' => $form->id, 'rating_period_id' => $context['period']->id,
            'core_average' => 0.0, 'final_average' => 0.0, 'adjectival' => 'Poor',
        ]);

        $data = $this->board($context);

        $this->assertNotNull($data['totals']['college_average'], 'A 0.00 average must not be dropped as if it were missing.');
        $this->assertEquals(0.0, $data['totals']['college_average']);
        $this->assertSame('Poor', $data['totals']['college_rating']);
    }

    public function test_a_unit_row_says_which_level_it_is(): void
    {
        $context = $this->college();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $context['unit']->update(['parent_id' => $college->id]);

        $data = $this->board($context);

        $rows = collect($data['units'])->keyBy('id');

        $this->assertSame('college', $rows[$college->id]['type']);
        $this->assertSame('program', $rows[$context['unit']->id]['type']);
        $this->assertSame($college->id, $rows[$context['unit']->id]['parent_id']);
        $this->assertSame('Opol Community College', $rows[$context['unit']->id]['parent_name']);
    }

    public function test_a_commitment_row_carries_its_unit(): void
    {
        $context = $this->college();
        $form    = $this->form($context);

        $this->line($form);

        $data = $this->board($context);

        $this->assertSame($context['unit']->id, $data['commitments'][0]['unit_id']);
    }
}
