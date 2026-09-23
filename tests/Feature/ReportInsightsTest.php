<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\User;
use Tests\PmsTestCase;

class ReportInsightsTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit(['name' => 'BS Information Technology', 'code' => 'BSIT']);
        $year = $this->makeSchoolYear(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $staff     = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'status' => 'active']);

        $ipcr = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id, 'user_id' => $staff->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
            'status' => 'rated', 'submitted_at' => '2026-03-04 09:00:00',
        ]);

        $output = PcrOutput::create(['form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Instruction']);

        $done = PcrIndicator::create([
            'output_id' => $output->id, 'description' => '<p>Finished line.</p>',
            'progress_status' => 'completed', 'progress_pct' => 100,
            'target_date' => '2026-02-01', 'completed_on' => '2026-01-30',
        ]);

        $late = PcrIndicator::create([
            'output_id' => $output->id, 'description' => '<p>Late line.</p>',
            'progress_status' => 'ongoing', 'progress_pct' => 20,
            'target_date' => now()->subDays(5)->toDateString(),
        ]);

        PcrPeriodSummary::create([
            'form_id' => $ipcr->id, 'rating_period_id' => $period->id,
            'core_average' => 4.6, 'final_average' => 4.6, 'adjectival' => 'Very Satisfactory',
        ]);

        return compact('unit', 'year', 'period', 'president', 'staff', 'ipcr', 'done', 'late');
    }

    public function test_the_board_counts_commitments_progress_and_what_is_late(): void
    {
        ['president' => $president, 'year' => $year] = $this->college();

        $this->actingAsUser($president);

        $data = $this->getJson("/api/reports/summary?school_year_id={$year->id}")
            ->assertSuccessful()
            ->json();

        $this->assertSame(2, $data['totals']['commitments']);
        $this->assertSame(1, $data['totals']['completed']);
        $this->assertSame(60, $data['totals']['progress_pct']);
        $this->assertSame(1, $data['totals']['overdue']);
        $this->assertSame(4.6, $data['totals']['college_average']);

        $core = collect($data['sections'])->firstWhere('section', 'core');

        $this->assertSame(2, $core['commitments']);
        $this->assertSame(60, $core['progress_pct']);
        $this->assertSame(4.6, $core['average']);

        $this->assertCount(1, $data['at_risk']);
        $this->assertSame('Late line.', $data['at_risk'][0]['description']);
        $this->assertSame(5, $data['at_risk'][0]['days_late']);
    }

    public function test_a_delegated_line_is_only_counted_once(): void
    {
        ['president' => $president, 'year' => $year, 'unit' => $unit, 'period' => $period, 'done' => $done] = $this->college();

        $helper = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $child  = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id, 'user_id' => $helper->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id, 'status' => 'draft',
        ]);
        $childOutput = PcrOutput::create(['form_id' => $child->id, 'section' => 'core', 'title' => 'Instruction']);

        PcrIndicator::create([
            'output_id' => $childOutput->id, 'parent_indicator_id' => $done->id,
            'description' => '<p>The delegated half.</p>', 'progress_pct' => 0,
        ]);

        $this->actingAsUser($president);

        $totals = $this->getJson("/api/reports/summary?school_year_id={$year->id}")->json('totals');

        $this->assertSame(2, $totals['commitments']);
    }

    public function test_the_year_reads_as_a_run_of_months_and_a_trend(): void
    {
        ['president' => $president, 'year' => $year] = $this->college();

        $this->actingAsUser($president);

        $data = $this->getJson("/api/reports/summary?school_year_id={$year->id}")->json();

        $this->assertCount(12, $data['submissions']);
        $this->assertSame('Jan', $data['submissions'][0]['label']);
        $this->assertSame(1, $data['submissions'][2]['submitted']);
        $this->assertSame(1, $data['submissions'][11]['cumulative']);

        $this->assertCount(1, $data['trend']);
        $this->assertSame(4.6, $data['trend'][0]['average']);

        $this->assertSame(1, $data['coverage']['expected']);
        $this->assertSame(1, $data['coverage']['filed']);
        $this->assertSame(100, $data['coverage']['pct']);
    }

    public function test_an_employee_cannot_read_the_board(): void
    {
        ['year' => $year, 'staff' => $staff] = $this->college();

        $this->actingAsUser($staff);

        $this->getJson("/api/reports/summary?school_year_id={$year->id}")->assertStatus(403);
    }

    public function test_a_table_can_be_taken_away_as_a_spreadsheet(): void
    {
        ['president' => $president, 'year' => $year] = $this->college();

        $this->actingAsUser($president);

        $response = $this->get("/api/reports/summary/export?school_year_id={$year->id}&table=commitments");

        $response->assertSuccessful();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Success indicator', $csv);
        $this->assertStringContainsString('Late line.', $csv);

        $this->get("/api/reports/summary/export?school_year_id={$year->id}&table=nonsense")
            ->assertStatus(422);
    }

    public function test_the_whole_report_prints_as_a_pdf(): void
    {
        ['president' => $president, 'year' => $year] = $this->college();

        $this->actingAsUser($president);

        $response = $this->get("/api/reports/summary/pdf?school_year_id={$year->id}");

        $response->assertSuccessful();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_the_exports_are_closed_to_an_employee(): void
    {
        ['year' => $year, 'staff' => $staff] = $this->college();

        $this->actingAsUser($staff);

        $this->getJson("/api/reports/summary/export?school_year_id={$year->id}&table=units")->assertStatus(403);
        $this->getJson("/api/reports/summary/pdf?school_year_id={$year->id}")->assertStatus(403);
    }

    public function test_it_ranks_people_and_heads(): void
    {
        ['president' => $president, 'year' => $year, 'staff' => $staff, 'unit' => $unit] = $this->college();

        $unit->update(['head_user_id' => $staff->id]);

        $this->actingAsUser($president);

        $data = $this->getJson("/api/reports/summary?school_year_id={$year->id}")->json();

        $person = collect($data['people'])->firstWhere('id', $staff->id);

        $this->assertSame(2, $person['commitments']);
        $this->assertSame(1, $person['completed']);
        $this->assertSame(60, $person['progress_pct']);
        $this->assertSame(1, $person['overdue']);
        $this->assertSame(5, $person['days_late']);
        $this->assertSame(4.6, $person['average']);

        $head = collect($data['heads'])->firstWhere('id', $unit->id);

        $this->assertSame($staff->name, $head['head']);
        $this->assertSame(60, $head['progress_pct']);
        $this->assertSame(1, $head['overdue']);
    }

    public function test_everyone_gets_their_own_counts(): void
    {
        ['staff' => $staff, 'year' => $year] = $this->college();

        $this->actingAsUser($staff);

        $mine = $this->getJson("/api/reports/my-summary?school_year_id={$year->id}")
            ->assertSuccessful()
            ->json();

        $this->assertSame(1, $mine['forms']['total']);
        $this->assertSame(1, $mine['forms']['rated']);
        $this->assertSame(2, $mine['commitments']['total']);
        $this->assertSame(1, $mine['commitments']['completed']);
        $this->assertSame(60, $mine['commitments']['progress_pct']);
        $this->assertSame(1, $mine['commitments']['overdue']);
        $this->assertSame(4.6, $mine['latest']['average']);
        $this->assertCount(1, $mine['at_risk']);
    }

    public function test_the_people_table_exports(): void
    {
        ['president' => $president, 'year' => $year] = $this->college();

        $this->actingAsUser($president);

        $csv = $this->get("/api/reports/summary/export?school_year_id={$year->id}&table=people")
            ->assertSuccessful()
            ->streamedContent();

        $this->assertStringContainsString('Days late', $csv);

        $this->get("/api/reports/summary/export?school_year_id={$year->id}&table=heads")
            ->assertSuccessful();
    }
}
