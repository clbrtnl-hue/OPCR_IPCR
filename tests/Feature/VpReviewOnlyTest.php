<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use App\Models\User;
use Tests\PmsTestCase;

class VpReviewOnlyTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();

        $vp   = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $head = User::factory()->create(['role' => 'program_head']);

        $office = $this->makeUnit([
            'name' => 'Research and Extension Office', 'code' => 'RES',
            'parent_id' => $college->id, 'head_user_id' => $head->id, 'vp_user_id' => $vp->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);

        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $vpIpcr = $this->makeForm([
            'org_unit_id' => $college->id, 'school_year_id' => $year->id, 'user_id' => $vp->id,
        ]);
        $teamIpcr = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        return compact('college', 'office', 'year', 'vp', 'head', 'faculty', 'opcr', 'target', 'vpIpcr', 'teamIpcr');
    }

    public function test_a_vp_has_no_assign_capabilities(): void
    {
        ['vp' => $vp] = $this->college();

        $this->assertSame(['assign_outputs' => false, 'assign_indicators' => false], $vp->capabilities);
    }

    public function test_a_vp_cannot_assign_from_their_own_ipcr(): void
    {
        ['vp' => $vp, 'vpIpcr' => $vpIpcr, 'faculty' => $faculty] = $this->college();

        $line = $this->makeIndicator($vpIpcr, 'core');

        $this->actingAsUser($vp);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$faculty->id]])->assertStatus(403);
        $this->postJson("/api/pcr-outputs/{$line->output_id}/assign", ['user_ids' => [$faculty->id]])->assertStatus(403);
    }

    public function test_a_vp_cannot_cascade_the_college_opcr(): void
    {
        ['vp' => $vp, 'opcr' => $opcr, 'faculty' => $faculty] = $this->college();

        $this->actingAsUser($vp);

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", ['user_ids' => [$faculty->id]])->assertStatus(403);
    }

    public function test_a_vp_cannot_withdraw_an_assignment(): void
    {
        ['vp' => $vp, 'target' => $target, 'teamIpcr' => $teamIpcr] = $this->college();

        $line  = $this->makeIndicator($teamIpcr, 'core');
        $child = PcrIndicator::find($line->id);
        $child->forceFill(['parent_indicator_id' => $target->id, 'assigned_by' => $vp->id])->save();

        $this->actingAsUser($vp);

        $this->deleteJson("/api/pcr-assignments/{$child->id}")->assertStatus(403);
        $this->assertDatabaseHas('pcr_indicators', ['id' => $child->id]);
    }

    public function test_a_vp_cannot_edit_a_team_members_ipcr(): void
    {
        ['vp' => $vp, 'teamIpcr' => $teamIpcr] = $this->college();

        $line = $this->makeIndicator($teamIpcr, 'core');

        $this->actingAsUser($vp);

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $teamIpcr->id, 'section' => 'core', 'title' => 'Written by the VP',
        ])->assertStatus(409);

        $this->postJson('/api/pcr-indicators', [
            'id' => $line->id, 'output_id' => $line->output_id, 'description' => 'Reworded by the VP',
        ])->assertStatus(409);
    }

    public function test_a_vp_cannot_edit_the_college_opcr(): void
    {
        ['vp' => $vp, 'opcr' => $opcr, 'target' => $target] = $this->college();

        $opcr->update(['status' => 'draft']);

        $this->actingAsUser($vp);

        $this->postJson('/api/pcr-indicators', [
            'id' => $target->id, 'output_id' => $target->output_id, 'description' => 'Reworded by the VP',
        ])->assertStatus(409);
    }

    public function test_a_vp_still_reviews_and_keeps_their_own_ipcr(): void
    {
        ['vp' => $vp, 'opcr' => $opcr, 'teamIpcr' => $teamIpcr, 'vpIpcr' => $vpIpcr] = $this->college();

        $this->actingAsUser($vp);

        $this->getJson("/api/pcr-forms/{$teamIpcr->id}")->assertOk();
        $this->getJson("/api/pcr-forms/{$opcr->id}")->assertOk();

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $vpIpcr->id, 'section' => 'core', 'title' => 'Academic oversight',
        ])->assertStatus(201);
    }

    public function test_a_vp_cannot_be_given_delegation_or_opcr_steps(): void
    {
        $this->college();

        $this->actingAsRole('admin');

        $this->postJson('/api/workflow-settings', [
            'key'   => 'delegation',
            'value' => ['assign_outputs' => ['president'], 'assign_indicators' => ['vp'], 'terminal_roles' => ['employee']],
        ])->assertStatus(422);

        $this->postJson('/api/workflow-settings', [
            'key'   => 'opcr',
            'value' => [
                'creator_roles' => ['vp'], 'approver_roles' => ['qa'], 'publisher_roles' => ['president'],
                'rating_trigger_roles' => ['president'], 'one_per' => 'organization',
            ],
        ])->assertStatus(422);
    }
}
