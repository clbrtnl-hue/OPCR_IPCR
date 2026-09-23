<?php

namespace Tests\Feature;

use App\Models\PcrPeriodSummary;
use App\Models\User;
use Tests\PmsTestCase;

class MyTeamTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $head = User::factory()->create(['role' => 'program_head']);
        $vp   = User::factory()->create(['role' => 'vp']);

        $bsit = $this->makeUnit(['head_user_id' => $head->id, 'vp_user_id' => $vp->id]);
        $registrar = $this->makeUnit([
            'name' => 'Office of the Registrar', 'code' => 'REG', 'vp_user_id' => $vp->id,
        ]);

        $head->update(['org_unit_id' => $bsit->id]);

        $novelyn  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $bsit->id]);
        $outsider = User::factory()->create(['role' => 'employee', 'org_unit_id' => $registrar->id]);

        return compact('year', 'period', 'head', 'vp', 'bsit', 'registrar', 'novelyn', 'outsider');
    }

    public function test_a_head_sees_a_member_whose_ipcr_is_still_a_draft(): void
    {
        ['year' => $year, 'period' => $period, 'head' => $head, 'novelyn' => $novelyn] = $this->college();

        $form = $this->makeForm([
            'user_id' => $novelyn->id, 'org_unit_id' => $novelyn->org_unit_id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
            'status' => 'draft',
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/my-team?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.id', $novelyn->id)
            ->assertJsonPath('members.0.form.id', $form->id)
            ->assertJsonPath('members.0.form.status', 'draft')
            ->assertJsonPath('members.0.form.outputs_count', 0);
    }

    public function test_a_member_without_a_form_still_appears(): void
    {
        ['year' => $year, 'period' => $period, 'head' => $head, 'novelyn' => $novelyn] = $this->college();

        $this->actingAsUser($head);

        $this->getJson("/api/my-team?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('members.0.id', $novelyn->id)
            ->assertJsonPath('members.0.form', null);
    }

    public function test_the_roster_stops_at_the_offices_a_person_leads(): void
    {
        ['year' => $year, 'head' => $head, 'outsider' => $outsider] = $this->college();

        $this->actingAsUser($head);

        $response = $this->getJson("/api/my-team?school_year_id={$year->id}")->assertOk();

        $this->assertNotContains($outsider->id, array_column($response->json('members'), 'id'));
        $this->assertCount(1, $response->json('units'));
    }

    public function test_a_vp_sees_every_office_they_oversee(): void
    {
        ['year' => $year, 'vp' => $vp, 'novelyn' => $novelyn, 'outsider' => $outsider] = $this->college();

        $this->actingAsUser($vp);

        $response = $this->getJson("/api/my-team?school_year_id={$year->id}")->assertOk();
        $ids      = array_column($response->json('members'), 'id');

        $this->assertContains($novelyn->id, $ids);
        $this->assertContains($outsider->id, $ids);
        $this->assertCount(2, $response->json('units'));
    }

    public function test_the_head_is_left_out_of_their_own_roster(): void
    {
        ['year' => $year, 'head' => $head] = $this->college();

        $this->actingAsUser($head);

        $response = $this->getJson("/api/my-team?school_year_id={$year->id}")->assertOk();

        $this->assertNotContains($head->id, array_column($response->json('members'), 'id'));
    }

    public function test_a_rated_form_carries_its_average(): void
    {
        ['year' => $year, 'period' => $period, 'head' => $head, 'novelyn' => $novelyn] = $this->college();

        $form = $this->makeForm([
            'user_id' => $novelyn->id, 'org_unit_id' => $novelyn->org_unit_id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
            'status' => 'final',
        ]);

        PcrPeriodSummary::create([
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'final_average'    => 4.75,
            'adjectival'       => 'Outstanding',
            'rated_indicators' => 4,
            'total_indicators' => 4,
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/my-team?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('members.0.form.average', 4.75)
            ->assertJsonPath('members.0.form.adjectival', 'Outstanding');
    }

    public function test_an_employee_may_not_read_the_roster(): void
    {
        ['novelyn' => $novelyn] = $this->college();

        $this->actingAsUser($novelyn);

        $this->getJson('/api/my-team')->assertStatus(403);
    }

    public function test_a_head_may_open_but_not_edit_a_members_draft(): void
    {
        ['year' => $year, 'period' => $period, 'head' => $head, 'novelyn' => $novelyn] = $this->college();

        $form = $this->makeForm([
            'user_id' => $novelyn->id, 'org_unit_id' => $novelyn->org_unit_id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
            'status' => 'draft',
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('id', $form->id);

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $form->id, 'section' => 'core', 'title' => 'Written by the head',
        ])->assertStatus(409);
    }
}
