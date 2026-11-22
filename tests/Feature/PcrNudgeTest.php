<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Tests\PmsTestCase;

class PcrNudgeTest extends PmsTestCase
{
    public function test_president_can_nudge_a_head_holding_a_form(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id, 'name' => 'Maria Head']);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'name' => 'Ruben Staff']);

        $form = $this->makeForm([
            'type'             => 'ipcr',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $head->id,
            'submitted_at'     => now()->subDays(5),
        ]);

        $this->actingAsUser($president);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")
            ->assertSuccessful()
            ->assertJsonPath('data', 'nudged');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'type'    => 'nudge',
            'form_id' => $form->id,
            'link'    => "/review-queue?tab=rating&form={$form->id}",
        ]);

        $this->assertStringContainsString('Ruben Staff', Notification::where('type', 'nudge')->value('body'));
    }

    public function test_commitment_nudge_opens_the_approval_queue(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $approver = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'name' => 'Ana Staff']);

        $form = $this->makeForm([
            'type'                    => 'ipcr',
            'org_unit_id'             => $unit->id,
            'school_year_id'          => $year->id,
            'user_id'                 => $owner->id,
            'status'                  => 'commitment_review',
            'commitment_approver_id'  => $approver->id,
            'submitted_at'            => now()->subDays(2),
        ]);

        $this->actingAsUser($president);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $approver->id,
            'type'    => 'nudge',
            'form_id' => $form->id,
            'link'    => "/review-queue?form={$form->id}",
        ]);
    }

    public function test_nudge_is_limited_to_once_per_day_per_actor(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'type'             => 'ipcr',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $head->id,
            'submitted_at'     => now()->subDays(3),
        ]);

        $this->actingAsUser($president);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")->assertSuccessful();
        $this->postJson("/api/pcr-forms/{$form->id}/nudge")->assertStatus(409);

        $this->assertSame(1, Notification::where('type', 'nudge')->where('form_id', $form->id)->count());
    }

    public function test_an_employee_cannot_nudge(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'type'             => 'ipcr',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $head->id,
        ]);

        $this->actingAsUser($employee);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")->assertStatus(403);
    }

    public function test_a_head_can_nudge_a_subordinate_with_a_draft(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();

        $head = User::factory()->create([
            'role' => 'program_head',
            'name' => 'Maria Head',
        ]);
        $unit = $this->makeUnit(['head_user_id' => $head->id]);
        $head->update(['org_unit_id' => $unit->id]);
        $owner = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $unit->id,
            'name'        => 'Ruben Staff',
        ]);

        $form = $this->makeForm([
            'type'           => 'ipcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
            'status'         => 'draft',
        ]);

        $this->actingAsUser($head);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")
            ->assertSuccessful()
            ->assertJsonPath('data', 'nudged');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type'    => 'nudge',
            'form_id' => $form->id,
            'title'   => 'Follow-up on your IPCR',
            'link'    => "/forms/{$form->id}",
        ]);

        $this->assertStringContainsString('Maria Head', Notification::where('type', 'nudge')->value('body'));
    }

    public function test_a_vp_can_nudge_a_subordinate_with_a_returned_form(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();

        $vp = User::factory()->create([
            'role' => 'vp',
            'name' => 'Vicente VP',
        ]);
        $unit = $this->makeUnit(['vp_user_id' => $vp->id]);
        $vp->update(['org_unit_id' => $unit->id]);
        $owner = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $unit->id,
        ]);

        $form = $this->makeForm([
            'type'           => 'ipcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
            'status'         => 'returned',
        ]);

        $this->actingAsUser($vp);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type'    => 'nudge',
            'form_id' => $form->id,
        ]);
        $this->assertStringContainsString('returned for correction', Notification::where('type', 'nudge')->value('body'));
    }

    public function test_nudge_is_blocked_when_the_period_is_locked(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);
        $period->update(['is_locked' => true]);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'type'             => 'ipcr',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $owner->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $head->id,
            'submitted_at'     => now()->subDays(4),
        ]);

        $this->actingAsUser($president);

        $this->postJson("/api/pcr-forms/{$form->id}/nudge")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This review period is locked. Unlock it before sending a follow-up.');

        $this->assertDatabaseMissing('notifications', [
            'type'    => 'nudge',
            'form_id' => $form->id,
        ]);
    }
}
