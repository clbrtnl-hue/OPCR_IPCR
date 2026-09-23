<?php

namespace Tests\Feature;

use App\Models\PcrOutput;
use App\Models\User;
use Tests\PmsTestCase;

class PcrOutputLinkTest extends PmsTestCase
{
    private function college(string $opcrStatus = 'published'): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => $opcrStatus,
        ]);
        $collegeOutput = PcrOutput::create([
            'form_id' => $opcr->id, 'section' => 'core', 'title' => 'Research',
        ]);

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ipcr     = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);

        return compact('unit', 'year', 'opcr', 'collegeOutput', 'employee', 'ipcr');
    }

    public function test_it_lists_the_colleges_headings(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'collegeOutput' => $collegeOutput] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->getJson("/api/pcr-forms/{$ipcr->id}/opcr-outputs")->assertSuccessful();

        $response->assertJsonCount(1)->assertJsonPath('0.title', 'Research');
        $this->assertSame($collegeOutput->id, $response->json('0.id'));
    }

    public function test_nothing_is_listed_while_the_opcr_is_unpublished(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->college('draft');

        $this->actingAsUser($employee);

        $this->getJson("/api/pcr-forms/{$ipcr->id}/opcr-outputs")->assertSuccessful()->assertJsonCount(0);
    }

    public function test_a_core_heading_takes_its_wording_from_the_college(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'collegeOutput' => $collegeOutput] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-outputs', [
            'form_id'          => $ipcr->id,
            'section'          => 'core',
            'title'            => 'Something the ratee typed instead',
            'parent_output_id' => $collegeOutput->id,
        ])->assertStatus(201);

        $output = PcrOutput::find($response->json('output.id'));

        $this->assertSame('Research', $output->title);
        $this->assertSame('core', $output->section);
        $this->assertSame($collegeOutput->id, (int) $output->parent_output_id);
    }

    public function test_a_core_heading_the_college_never_named_is_the_ratees_own(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'core',
            'title'   => 'Advising of OJT students',
        ])->assertStatus(201);

        $output = PcrOutput::find($response->json('output.id'));

        $this->assertSame('Advising of OJT students', $output->title);
        $this->assertNull($output->parent_output_id);
    }

    public function test_a_typed_heading_matching_the_college_stays_the_ratees_own(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'core',
            'title'   => 'research',
        ])->assertStatus(201);

        $output = PcrOutput::find($response->json('output.id'));

        $this->assertSame('research', $output->title);
        $this->assertNull($output->parent_output_id);
    }

    public function test_an_unassigned_linked_heading_is_unlinked_when_renamed(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'collegeOutput' => $collegeOutput] = $this->college();

        $mine = PcrOutput::create([
            'form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Research',
            'parent_output_id' => $collegeOutput->id,
        ]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-outputs', [
            'id' => $mine->id, 'form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Thesis advising',
        ])->assertStatus(200);

        $mine->refresh();
        $this->assertSame('Thesis advising', $mine->title);
        $this->assertNull($mine->parent_output_id);

        $this->deleteJson("/api/pcr-outputs/{$mine->id}")->assertStatus(200);
    }

    public function test_a_typed_heading_ignores_an_unpublished_college_opcr(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->college('draft');

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'core',
            'title'   => 'Research',
        ])->assertStatus(201);

        $this->assertNull(PcrOutput::find($response->json('output.id'))->parent_output_id);
    }

    public function test_a_typed_heading_does_not_match_across_school_years(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'unit' => $unit, 'opcr' => $opcr] = $this->college();

        $opcr->outputs()->delete();

        $otherYear = $this->makeSchoolYear([
            'label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]);
        $otherOpcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $otherYear->id, 'status' => 'published',
        ]);
        PcrOutput::create([
            'form_id' => $otherOpcr->id, 'section' => 'core', 'title' => 'Research',
        ]);

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'core',
            'title'   => 'Research',
        ])->assertStatus(201);

        $this->assertNull(PcrOutput::find($response->json('output.id'))->parent_output_id);
    }

    public function test_a_support_heading_is_never_linked_to_the_college(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'opcr' => $opcr] = $this->college();

        PcrOutput::create([
            'form_id' => $opcr->id, 'section' => 'support', 'title' => 'MANCOM meetings',
        ]);

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'support',
            'title'   => 'MANCOM meetings',
        ])->assertStatus(201);

        $this->assertNull(PcrOutput::find($response->json('output.id'))->parent_output_id);
    }

    public function test_a_heading_still_needs_a_name(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->college();

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'core',
        ])->assertStatus(422);
    }

    public function test_support_functions_are_the_ratees_own(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr] = $this->college();

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'support',
            'title'   => 'Submission of DTR',
        ])->assertStatus(201);
    }

    public function test_a_heading_from_another_year_is_refused(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'unit' => $unit] = $this->college();

        $otherYear = $this->makeSchoolYear([
            'label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]);
        $otherOpcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $otherYear->id, 'status' => 'published',
        ]);
        $foreign = PcrOutput::create([
            'form_id' => $otherOpcr->id, 'section' => 'core', 'title' => 'Research',
        ]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-outputs', [
            'form_id'          => $ipcr->id,
            'section'          => 'core',
            'parent_output_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_a_core_heading_is_free_when_the_college_named_none(): void
    {
        $this->makeOrganization();

        $unit     = $this->makeUnit();
        $year     = $this->makeSchoolYear();
        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ipcr     = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);

        $this->actingAsUser($employee);

        // No published OPCR at all, so nothing to answer to yet.
        $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id,
            'section' => 'core',
            'title'   => 'Research',
        ])->assertStatus(201);
    }

    public function test_a_delegated_heading_can_be_renamed_and_stays_linked(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'collegeOutput' => $collegeOutput] = $this->college();

        $mine = PcrOutput::create([
            'form_id'          => $ipcr->id,
            'section'          => 'core',
            'title'            => 'Research',
            'parent_output_id' => $collegeOutput->id,
            'assigned_by'      => User::factory()->create()->id,
        ]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-outputs', [
            'id'      => $mine->id,
            'form_id' => $ipcr->id,
            'section' => 'core',
            'title'   => 'Renamed to whatever I like',
        ])->assertStatus(200);

        $mine->refresh();

        $this->assertSame('Renamed to whatever I like', $mine->title);
        $this->assertSame($collegeOutput->id, (int) $mine->parent_output_id);
    }

    public function test_an_assigned_strategic_heading_can_be_renamed_but_not_added(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'opcr' => $opcr] = $this->college();

        $college = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Modernization']);
        $mine    = PcrOutput::create([
            'form_id' => $ipcr->id, 'section' => 'strategic', 'title' => 'Modernization',
            'parent_output_id' => $college->id, 'assigned_by' => User::factory()->create()->id,
        ]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-outputs', [
            'id' => $mine->id, 'form_id' => $ipcr->id, 'section' => 'strategic', 'title' => 'Campus modernization',
        ])->assertStatus(200);

        $this->assertSame('Campus modernization', $mine->fresh()->title);

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $ipcr->id, 'section' => 'strategic', 'title' => 'Something new',
        ])->assertStatus(422);
    }

    public function test_a_delegated_heading_cannot_be_deleted_from_the_ipcr(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'collegeOutput' => $collegeOutput] = $this->college();

        $mine = PcrOutput::create([
            'form_id'          => $ipcr->id,
            'section'          => 'core',
            'title'            => 'Research',
            'parent_output_id' => $collegeOutput->id,
            'assigned_by'      => User::factory()->create()->id,
        ]);

        $own = PcrOutput::create([
            'form_id' => $ipcr->id, 'section' => 'support', 'title' => 'Submission of DTR',
        ]);

        $this->actingAsUser($employee);

        $this->deleteJson("/api/pcr-outputs/{$mine->id}")->assertStatus(409);
        $this->assertNotNull(PcrOutput::find($mine->id));

        $this->deleteJson("/api/pcr-outputs/{$own->id}")->assertStatus(200);
        $this->assertNull(PcrOutput::find($own->id));
    }
}
