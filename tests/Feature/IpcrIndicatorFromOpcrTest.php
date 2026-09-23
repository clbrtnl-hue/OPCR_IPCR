<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\User;
use Tests\PmsTestCase;

class IpcrIndicatorFromOpcrTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $research  = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'Research']);
        $extension = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'Extension']);

        $target = PcrIndicator::create([
            'output_id' => $research->id, 'description' => 'Publish 25 peer-reviewed articles.',
            'target_date' => '2026-12-15',
        ]);
        $otherTarget = PcrIndicator::create([
            'output_id' => $extension->id, 'description' => 'Run 4 community programs.',
        ]);

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ipcr     = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);
        $heading = PcrOutput::create([
            'form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Research',
            'parent_output_id' => $research->id, 'assigned_by' => User::factory()->create()->id,
        ]);

        return compact('unit', 'year', 'opcr', 'research', 'target', 'otherTarget', 'employee', 'ipcr', 'heading');
    }

    public function test_targets_name_the_college_mfo_they_sit_under(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'research' => $research] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->getJson("/api/pcr-forms/{$ipcr->id}/opcr-targets")->assertSuccessful();

        $this->assertSame($research->id, $response->json('0.output_id'));
    }

    public function test_picking_a_target_keeps_the_ratees_wording(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'target' => $target] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'           => $heading->id,
            'description'         => 'Facilitate publication of 3 department articles.',
            'parent_indicator_id' => $target->id,
        ])->assertStatus(201);

        $line = PcrIndicator::find($response->json('indicator.id'));
        $this->assertSame($target->id, $line->parent_indicator_id);
        $this->assertStringContainsString('Facilitate publication of 3 department articles.', $line->description);
    }

    public function test_a_picked_target_still_needs_a_typed_description(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'target' => $target] = $this->college();

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $heading->id,
            'parent_indicator_id' => $target->id,
        ])->assertStatus(422);
    }

    public function test_a_line_without_a_target_still_needs_a_description(): void
    {
        ['employee' => $employee, 'heading' => $heading] = $this->college();

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-indicators', ['output_id' => $heading->id])->assertStatus(422);
    }

    public function test_a_typed_line_under_a_core_heading_stays_the_ratees_own(): void
    {
        ['employee' => $employee, 'heading' => $heading] = $this->college();

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'   => $heading->id,
            'description' => 'Mentor 2 thesis groups.',
        ])->assertStatus(201);

        $this->assertNull(PcrIndicator::find($response->json('indicator.id'))->parent_indicator_id);
    }

    public function test_a_target_under_another_college_mfo_is_refused(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'otherTarget' => $otherTarget] = $this->college();

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $heading->id,
            'parent_indicator_id' => $otherTarget->id,
        ])->assertStatus(422);
    }

    public function test_an_unlinked_heading_may_pick_any_target(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'otherTarget' => $otherTarget] = $this->college();

        $this->actingAsUser($employee);
        $own = PcrOutput::create(['form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Mentoring']);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $own->id,
            'description'         => 'Adopt two barangays this year.',
            'parent_indicator_id' => $otherTarget->id,
        ])->assertStatus(201);
    }

    public function test_a_typed_heading_named_like_the_college_may_pick_any_target(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'otherTarget' => $otherTarget, 'research' => $research] = $this->college();

        $this->actingAsUser($employee);
        $typed = PcrOutput::create([
            'form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Research', 'parent_output_id' => $research->id,
        ]);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $typed->id,
            'description'         => 'Host a research colloquium.',
            'parent_indicator_id' => $otherTarget->id,
        ])->assertStatus(201);
    }

    public function test_a_support_line_cannot_pick_a_target(): void
    {
        ['employee' => $employee, 'ipcr' => $ipcr, 'target' => $target] = $this->college();

        $this->actingAsUser($employee);
        $support = PcrOutput::create(['form_id' => $ipcr->id, 'section' => 'support', 'title' => 'DTR']);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $support->id,
            'parent_indicator_id' => $target->id,
        ])->assertStatus(422);
    }

    public function test_an_assigned_line_keeps_its_reworded_description(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'target' => $target] = $this->college();

        $line = PcrIndicator::create([
            'output_id' => $heading->id, 'description' => $target->description,
            'parent_indicator_id' => $target->id, 'assigned_by' => User::factory()->create()->id,
        ]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-indicators', [
            'id'                  => $line->id,
            'output_id'           => $heading->id,
            'description'         => 'Publish 3 of the college articles.',
            'parent_indicator_id' => $target->id,
        ])->assertSuccessful();

        $this->assertStringContainsString('Publish 3 of the college articles.', $line->fresh()->description);
    }

    public function test_the_pick_shows_on_the_opcr_as_delivered_by_the_ratee(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'target' => $target, 'opcr' => $opcr] = $this->college();

        $this->actingAsUser($employee);
        $lineId = $this->postJson('/api/pcr-indicators', [
            'output_id'           => $heading->id,
            'description'         => 'Facilitate publication of 3 department articles.',
            'parent_indicator_id' => $target->id,
        ])->json('indicator.id');

        $this->actingAsRole('admin');

        $form     = $this->getJson("/api/pcr-forms/{$opcr->id}")->assertSuccessful();
        $children = collect($form->json('outputs'))
            ->flatMap(fn ($o) => $o['indicators'])
            ->firstWhere('id', $target->id)['children'];

        $this->assertSame($lineId, $children[0]['id']);
        $this->assertSame($employee->id, $children[0]['output']['form']['owner']['id']);

        $rollup = $this->getJson("/api/pcr-indicators/{$target->id}/rollup")->assertSuccessful();
        $rollup->assertJsonPath('delivered.0.child_id', $lineId);
        $rollup->assertJsonPath('delivered.0.assigned_by', null);
    }

    public function test_linking_and_unlinking_moves_the_office_targets_progress(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'target' => $target] = $this->college();

        $line = PcrIndicator::create([
            'output_id' => $heading->id, 'description' => 'Mine',
            'progress_status' => 'completed', 'progress_pct' => 100,
        ]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-indicators', [
            'id' => $line->id, 'output_id' => $heading->id, 'parent_indicator_id' => $target->id,
        ])->assertSuccessful();

        $this->assertSame(100, (int) $target->fresh()->progress_pct);

        $this->postJson('/api/pcr-indicators', [
            'id' => $line->id, 'output_id' => $heading->id, 'description' => 'Mine again',
        ])->assertSuccessful();

        $this->assertSame(0, (int) $target->fresh()->progress_pct);
    }

    public function test_deleting_a_picked_line_releases_the_office_target(): void
    {
        ['employee' => $employee, 'heading' => $heading, 'target' => $target] = $this->college();

        $line = PcrIndicator::create([
            'output_id' => $heading->id, 'description' => $target->description,
            'parent_indicator_id' => $target->id, 'progress_status' => 'completed', 'progress_pct' => 100,
        ]);
        $target->forceFill(['progress_status' => 'completed', 'progress_pct' => 100])->save();

        $this->actingAsUser($employee);
        $this->deleteJson("/api/pcr-indicators/{$line->id}")->assertSuccessful();

        $this->assertSame(0, (int) $target->fresh()->progress_pct);
    }

    public function test_a_picked_line_cannot_be_withdrawn_as_an_assignment(): void
    {
        ['heading' => $heading, 'target' => $target] = $this->college();

        $line = PcrIndicator::create([
            'output_id' => $heading->id, 'description' => $target->description,
            'parent_indicator_id' => $target->id,
        ]);

        $this->actingAsRole('admin');
        $this->deleteJson("/api/pcr-assignments/{$line->id}")->assertStatus(409);

        $this->assertDatabaseHas('pcr_indicators', ['id' => $line->id]);
    }
}
