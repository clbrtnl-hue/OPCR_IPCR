<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * The OPCR is built as a sheet: rows are added, reordered and checked over
 * before it goes to QA, and a new year can start from the last one.
 */
class OpcrSheetTest extends PmsTestCase
{
    private function college(string $status = 'draft'): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year = $this->makeSchoolYear();
        $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $opcr      = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => $status,
        ]);

        return compact('unit', 'year', 'president', 'opcr');
    }

    public function test_it_reports_what_still_needs_attention(): void
    {
        ['president' => $president, 'opcr' => $opcr] = $this->college();

        $this->actingAsUser($president);

        // Nothing committed to at all.
        $this->getJson("/api/pcr-forms/{$opcr->id}/readiness")
            ->assertSuccessful()
            ->assertJsonPath('ready', false);

        $output = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'New MFO/PPA']);

        $issues = $this->getJson("/api/pcr-forms/{$opcr->id}/readiness")->json('issues');

        $this->assertNotEmpty(
            array_filter($issues, fn ($i) => str_contains($i, 'needs a name')),
            'An unnamed MFO/PPA should be reported.'
        );
        $this->assertNotEmpty(
            array_filter($issues, fn ($i) => str_contains($i, 'no success indicator')),
            'An empty MFO/PPA should be reported.'
        );

        // A named heading with an accountable line reads as ready.
        $output->update(['title' => 'Research']);
        PcrIndicator::create([
            'output_id'   => $output->id,
            'description' => '<p>Publish 25 peer-reviewed researches.</p>',
            'accountable' => 'Ronnel D. Pinto',
        ]);

        $this->getJson("/api/pcr-forms/{$opcr->id}/readiness")->assertJsonPath('ready', true);
    }

    public function test_support_functions_need_nobody_named(): void
    {
        ['president' => $president, 'opcr' => $opcr] = $this->college();

        $support = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'support', 'title' => 'MANCOM meetings']);
        PcrIndicator::create([
            'output_id'   => $support->id,
            'description' => '<p>Attended all Management Committee meetings.</p>',
        ]);

        $this->actingAsUser($president);

        // The paper form leaves the accountable column blank here too.
        $this->getJson("/api/pcr-forms/{$opcr->id}/readiness")->assertJsonPath('ready', true);
    }

    public function test_a_new_year_can_start_from_the_last(): void
    {
        ['unit' => $unit, 'president' => $president, 'opcr' => $last] = $this->college('final');

        $output = PcrOutput::create(['form_id' => $last->id, 'section' => 'core', 'title' => 'Research']);
        PcrIndicator::create([
            'output_id'       => $output->id,
            'description'     => '<p>Publish 25 peer-reviewed researches.</p>',
            'allotted_budget' => 250000,
        ]);

        $nextYear = $this->makeSchoolYear([
            'label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]);
        $this->makePeriod($nextYear, 1);

        $fresh = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $nextYear->id, 'status' => 'draft',
        ]);

        $this->actingAsUser($president);

        $this->postJson("/api/pcr-forms/{$fresh->id}/copy-from", ['source_form_id' => $last->id])
            ->assertSuccessful()
            ->assertJsonPath('lines', 1);

        $copied = $fresh->fresh()->outputs()->with('indicators')->first();

        $this->assertSame('Research', $copied->title);
        $this->assertSame(250000.0, (float) $copied->indicators->first()->allotted_budget);

        // The new year's lines belong to the new year's period.
        $this->assertSame(
            $nextYear->periods()->orderBy('seq')->value('id'),
            (int) $copied->indicators->first()->rating_period_id
        );
    }

    public function test_it_refuses_to_copy_over_existing_work(): void
    {
        ['unit' => $unit, 'president' => $president, 'opcr' => $last] = $this->college('final');

        PcrOutput::create(['form_id' => $last->id, 'section' => 'core', 'title' => 'Research']);

        $nextYear = $this->makeSchoolYear([
            'label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]);
        $fresh = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $nextYear->id, 'status' => 'draft',
        ]);
        PcrOutput::create(['form_id' => $fresh->id, 'section' => 'core', 'title' => 'Already here']);

        $this->actingAsUser($president);

        $this->postJson("/api/pcr-forms/{$fresh->id}/copy-from", ['source_form_id' => $last->id])
            ->assertStatus(409);
    }

    public function test_rows_can_be_reordered(): void
    {
        ['president' => $president, 'opcr' => $opcr] = $this->college();

        $first  = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'First', 'sort_order' => 1]);
        $second = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'Second', 'sort_order' => 2]);

        $this->actingAsUser($president);

        // The sheet swaps the two positions to move a row.
        $this->postJson('/api/pcr-outputs', [
            'id' => $first->id, 'form_id' => $opcr->id, 'section' => 'core',
            'title' => 'First', 'sort_order' => 2,
        ])->assertSuccessful();

        $this->postJson('/api/pcr-outputs', [
            'id' => $second->id, 'form_id' => $opcr->id, 'section' => 'core',
            'title' => 'Second', 'sort_order' => 1,
        ])->assertSuccessful();

        $this->assertSame(
            ['Second', 'First'],
            $opcr->outputs()->orderBy('sort_order')->pluck('title')->all()
        );
    }

    public function test_the_sheet_is_told_how_far_each_line_has_got(): void
    {
        ['president' => $president, 'opcr' => $opcr] = $this->college();

        $output = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'support', 'title' => 'MANCOM meetings']);
        $line   = PcrIndicator::create([
            'output_id'   => $output->id,
            'description' => '<p>Attended all Management Committee meetings.</p>',
        ]);

        $this->actingAsUser($president);

        $this->getJson("/api/pcr-forms/{$opcr->id}")
            ->assertSuccessful()
            ->assertJsonPath('outputs.0.child_outputs_count', 0)
            ->assertJsonPath('outputs.0.indicators.0.progress_status', 'not_started')
            ->assertJsonPath('outputs.0.indicators.0.progress_pct', 0);

        $this->postJson("/api/pcr-indicators/{$line->id}/progress", [
            'progress_status' => 'ongoing',
            'progress_pct'    => 60,
        ])->assertSuccessful();

        $this->assertSame(60, (int) $line->fresh()->progress_pct);

        $this->postJson("/api/pcr-indicators/{$line->id}/progress", [
            'progress_status' => 'completed',
        ])->assertSuccessful();

        $this->getJson("/api/pcr-forms/{$opcr->id}")
            ->assertJsonPath('outputs.0.indicators.0.progress_status', 'completed')
            ->assertJsonPath('outputs.0.indicators.0.progress_pct', 100);
    }
}
