<?php

namespace Tests\Feature;

use App\Models\PcrAccomplishment;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

/**
 * The president writes the office-level actual accomplishment on the OPCR
 * line. That text is what QA rates. It must not appear on anyone's IPCR;
 * those forms keep their own delivery records (the rollup).
 */
class OpcrAccomplishmentTest extends PmsTestCase
{
    public function test_the_president_records_an_office_accomplishment_on_the_opcr(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $indicator = $this->makeIndicator($opcr, 'core');

        $this->actingAsUser($president);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'The college met 75% of the online exam target.',
        ])->assertOk();

        $this->assertDatabaseHas('pcr_accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => '<p>The college met 75% of the online exam target.</p>',
        ]);
    }

    public function test_an_opcr_accomplishment_is_not_copied_onto_an_assignees_ipcr(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $line = $this->makeIndicator($opcr, 'core');

        $this->actingAsUser($president);
        app(PcrAssignmentService::class)->assignIndicator($line, $head, $president, $period->id, false);

        PcrAccomplishment::create([
            'indicator_id'          => $line->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => '<p>College-level summary for QA.</p>',
        ]);

        $this->actingAsUser($head);
        $child = $this->commitAgainst($line, $head, ['description' => 'Schedule the entrance exam window.']);

        $ipcr = $this->getJson("/api/pcr-forms/{$child->output->form_id}")->assertOk()->json();
        $this->assertStringNotContainsString('College-level summary for QA', json_encode($ipcr));
        $this->assertStringContainsString('Schedule the entrance exam window', json_encode($ipcr));
    }
}
