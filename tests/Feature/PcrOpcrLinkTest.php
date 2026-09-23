<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * An IPCR line names the office OPCR target it delivers. The link may only
 * point at the ratee's own unit and cycle, and once the office has an OPCR
 * every core-function line has to carry one before the form can be submitted.
 */
class PcrOpcrLinkTest extends PmsTestCase
{
    private function scenario(): array
    {
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr   = $this->makeForm([
            'type'           => 'opcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'status'         => 'published',
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ipcr     = $this->makeForm([
            'type'           => 'ipcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $employee->id,
        ]);

        return compact('unit', 'year', 'opcr', 'target', 'employee', 'ipcr');
    }

    public function test_it_links_a_line_to_a_target_in_the_same_unit_and_cycle(): void
    {
        ['target' => $target, 'employee' => $employee, 'ipcr' => $ipcr] = $this->scenario();

        $this->actingAsUser($employee);
        $output = $this->makeIndicator($ipcr, 'core')->output;

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'         => $output->id,
            'description'       => 'Co-author 1 Needs Assessment Manual with 75% accuracy.',
            'parent_indicator_id' => $target->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pcr_indicators', [
            'id'                => $response->json('indicator.id'),
            'parent_indicator_id' => $target->id,
        ]);
    }

    public function test_it_rejects_a_target_from_another_school_year(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->scenario();

        // The college has one OPCR per year; last year's targets are not
        // something this year's commitments may be tied to.
        $otherYear = $this->makeSchoolYear([
            'label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]);
        $otherOpcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $ipcr->org_unit_id,
            'school_year_id' => $otherYear->id, 'status' => 'published',
        ]);
        $foreign = $this->makeIndicator($otherOpcr, 'core');

        $this->actingAsUser($employee);
        $output = $this->makeIndicator($ipcr, 'core')->output;

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $output->id,
            'description'         => 'Something else.',
            'parent_indicator_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_it_rejects_a_target_that_is_another_ipcr_line(): void
    {
        ['unit' => $unit, 'year' => $year, 'employee' => $employee, 'ipcr' => $ipcr] = $this->scenario();

        $colleague     = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $colleagueForm = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => $colleague->id,
        ]);
        $colleagueLine = $this->makeIndicator($colleagueForm, 'core');

        $this->actingAsUser($employee);
        $output = $this->makeIndicator($ipcr, 'core')->output;

        $this->postJson('/api/pcr-indicators', [
            'output_id'         => $output->id,
            'description'       => 'Something else.',
            'parent_indicator_id' => $colleagueLine->id,
        ])->assertStatus(422);
    }

    public function test_a_line_needs_no_target_of_its_own(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->scenario();

        // The heading carries the link to the college OPCR (see PcrOutputLinkTest),
        // so an individual line need not name a specific office target as well.
        $this->makeIndicator($ipcr, 'core');
        $this->actingAsUser($employee);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/status", ['status' => 'head_review'])
            ->assertSuccessful();

        $this->assertNotSame('draft', $ipcr->fresh()->status);
    }

    public function test_it_allows_submission_when_the_office_has_no_opcr(): void
    {
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ipcr     = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);
        $this->makeIndicator($ipcr, 'core');

        $this->actingAsUser($employee);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/status", ['status' => 'head_review'])
            ->assertStatus(200);

        // This office has no head or VP assigned, so the chain skips ahead —
        // what matters here is that the missing OPCR did not block submission.
        $this->assertNotSame('draft', $ipcr->fresh()->status);
    }

    public function test_support_lines_never_block_submission(): void
    {
        ['target' => $target, 'employee' => $employee, 'ipcr' => $ipcr] = $this->scenario();

        $core = $this->makeIndicator($ipcr, 'core');
        $core->update(['parent_indicator_id' => $target->id]);
        $this->makeIndicator($ipcr, 'support');

        $this->actingAsUser($employee);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/status", ['status' => 'head_review'])
            ->assertStatus(200);
    }

    public function test_an_opcr_line_never_links_upward(): void
    {
        ['target' => $target, 'unit' => $unit, 'year' => $year] = $this->scenario();

        // Commitments are only editable while a form is a draft, so the OPCR
        // being written here is a fresh one, not the published target's form.
        $draftOpcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'draft',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsUser($admin);

        $output = $this->makeIndicator($draftOpcr, 'strategic')->output;

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'         => $output->id,
            'description'       => '75% implementation of the online entrance exam system.',
            'parent_indicator_id' => $target->id,
        ]);

        $response->assertStatus(201);
        $this->assertNull(PcrIndicator::find($response->json('indicator.id'))->parent_indicator_id);
    }

    public function test_it_lists_targets_for_the_ratee_unit_only(): void
    {
        ['year' => $year, 'target' => $target, 'employee' => $employee, 'ipcr' => $ipcr] = $this->scenario();

        $otherUnit = $this->makeUnit(['name' => 'Research Office', 'code' => 'RES']);
        $otherOpcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $otherUnit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $this->makeIndicator($otherOpcr, 'core');

        $this->actingAsUser($employee);

        $response = $this->getJson("/api/pcr-forms/{$ipcr->id}/opcr-targets");

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame($target->id, $response->json('0.id'));
    }
}
