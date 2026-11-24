<?php

namespace Tests\Feature;

use App\Models\OrgUnitReviewer;
use App\Models\PcrIndicator;
use App\Models\User;
use Tests\PmsTestCase;

class TeamProgressTest extends PmsTestCase
{
    public function test_a_head_sees_each_subordinate_and_not_their_own_lines(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $head = User::factory()->create(['role' => 'program_head', 'name' => 'Head Person']);
        $unit = $this->makeUnit(['head_user_id' => $head->id]);
        $head->update(['org_unit_id' => $unit->id]);

        $slow = User::factory()->create([
            'role' => 'employee', 'name' => 'Slow Staff', 'org_unit_id' => $unit->id,
        ]);
        $idle = User::factory()->create([
            'role' => 'employee', 'name' => 'Idle Staff', 'org_unit_id' => $unit->id,
        ]);
        $other = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG']);
        $outsider = User::factory()->create([
            'role' => 'employee', 'name' => 'Outsider', 'org_unit_id' => $other->id,
        ]);

        $form = $this->makeForm([
            'user_id' => $slow->id, 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($form, 'core', [
            'progress_pct' => 40, 'progress_status' => 'ongoing',
            'rating_period_id' => $period->id,
            'target_date' => now()->subDays(3)->toDateString(),
        ]);
        $this->makeIndicator($form, 'core', [
            'progress_pct' => 80, 'progress_status' => 'ongoing',
            'rating_period_id' => $period->id,
        ]);

        $own = $this->makeForm([
            'user_id' => $head->id, 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($own, 'core', [
            'progress_pct' => 100, 'progress_status' => 'completed',
            'rating_period_id' => $period->id,
        ]);

        $outside = $this->makeForm([
            'user_id' => $outsider->id, 'org_unit_id' => $other->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($outside, 'core', [
            'progress_pct' => 100, 'progress_status' => 'completed',
            'rating_period_id' => $period->id,
        ]);

        $this->actingAsUser($head);

        $response = $this->getJson("/api/my-team/progress?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('view', 'people')
            ->assertJsonPath('totals.people', 2)
            ->assertJsonPath('totals.started', 1)
            ->assertJsonPath('totals.progress_pct', 60)
            ->assertJsonPath('totals.overdue', 1);

        $ids = array_column($response->json('rows'), 'id');

        $this->assertContains($slow->id, $ids);
        $this->assertContains($idle->id, $ids);
        $this->assertNotContains($head->id, $ids);
        $this->assertNotContains($outsider->id, $ids);

        $slowRow = collect($response->json('rows'))->firstWhere('id', $slow->id);

        $this->assertSame(60, $slowRow['progress_pct']);
        $this->assertSame(2, $slowRow['commitments']);
        $this->assertSame(1, $slowRow['overdue']);
        $this->assertSame(1, $slowRow['drafting']);
        $this->assertSame(0, $slowRow['submitted']);
        $this->assertSame(0, $slowRow['rated']);
        $this->assertSame(1, collect($response->json('rows'))->firstWhere('id', $idle->id)['drafting']);
        $this->assertSame($form->id, $slowRow['form_id']);
        $this->assertNull(collect($response->json('rows'))->firstWhere('id', $idle->id)['progress_pct']);
    }

    public function test_a_parent_line_does_not_count_when_the_commitment_sits_on_its_child(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);
        $head = User::factory()->create(['role' => 'program_head']);
        $unit = $this->makeUnit(['head_user_id' => $head->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'user_id' => $staff->id, 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
        ]);
        $parent = $this->makeIndicator($form, 'core', [
            'progress_pct' => 0, 'progress_status' => 'not_started',
            'rating_period_id' => $period->id,
        ]);
        PcrIndicator::create([
            'output_id'           => $parent->output_id,
            'parent_indicator_id' => $parent->id,
            'description'         => 'The commitment the staff actually owns.',
            'rating_period_id'    => $period->id,
            'progress_pct'        => 80,
            'progress_status'     => 'ongoing',
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/my-team/progress?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('rows.0.progress_pct', 80)
            ->assertJsonPath('rows.0.commitments', 1);
    }

    public function test_a_replaced_commitment_is_not_counted_on_the_team(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);
        $head = User::factory()->create(['role' => 'program_head']);
        $unit = $this->makeUnit(['head_user_id' => $head->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'user_id' => $staff->id, 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
        ]);
        $live = $this->makeIndicator($form, 'core', [
            'progress_pct' => 80, 'progress_status' => 'ongoing',
            'rating_period_id' => $period->id,
        ]);
        PcrIndicator::create([
            'output_id'       => $live->output_id,
            'description'     => 'The commitment that was replaced.',
            'rating_period_id'=> $period->id,
            'progress_pct'    => 0,
            'progress_status' => 'not_started',
            'closed_at'       => now(),
            'closed_reason'   => 'target_replaced',
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/my-team/progress?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('rows.0.progress_pct', 80)
            ->assertJsonPath('rows.0.commitments', 1);
    }

    public function test_a_vp_sees_each_office_under_them_rolled_up(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $vp = User::factory()->create(['role' => 'vp', 'name' => 'Academic VP']);
        $division = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AA', 'type' => 'office', 'vp_user_id' => $vp->id,
        ]);
        $ahead = $this->makeUnit([
            'name' => 'College of Nursing', 'code' => 'CON', 'type' => 'office', 'parent_id' => $division->id,
        ]);
        $behind = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'type' => 'office', 'parent_id' => $division->id,
        ]);
        $program = $this->makeUnit([
            'name' => 'BS Nursing', 'code' => 'BSN', 'parent_id' => $ahead->id,
        ]);

        $nurse = User::factory()->create(['role' => 'employee', 'org_unit_id' => $program->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $behind->id]);
        $secretary = User::factory()->create(['role' => 'employee', 'org_unit_id' => $division->id]);

        foreach ([[$nurse, $program, 100], [$faculty, $behind, 40], [$secretary, $division, 80]] as [$person, $unit, $pct]) {
            $form = $this->makeForm([
                'user_id' => $person->id, 'org_unit_id' => $unit->id,
                'school_year_id' => $year->id, 'rating_period_id' => $period->id,
            ]);
            $this->makeIndicator($form, 'core', [
                'progress_pct' => $pct, 'progress_status' => 'ongoing',
                'rating_period_id' => $period->id,
            ]);
        }

        $this->actingAsUser($vp);

        $response = $this->getJson("/api/my-team/progress?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('view', 'units')
            ->assertJsonPath('totals.people', 3);

        $rows = collect($response->json('rows'))->keyBy('name');

        $this->assertSame(100, $rows['College of Nursing']['progress_pct']);
        $this->assertSame(1, $rows['College of Nursing']['people']);
        $this->assertSame(40, $rows['College of IT']['progress_pct']);
        $this->assertTrue($rows['Academic Affairs']['direct']);
        $this->assertSame(80, $rows['Academic Affairs']['progress_pct']);
        $this->assertSame(1, $rows['Academic Affairs']['people']);
        $this->assertSame(73, $response->json('totals.progress_pct'));
    }

    public function test_another_period_does_not_change_the_one_on_screen(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $first = $this->makePeriod($year, 1);
        $second = $this->makePeriod($year, 2);
        $head = User::factory()->create(['role' => 'program_head']);
        $unit = $this->makeUnit(['head_user_id' => $head->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $early = $this->makeForm([
            'user_id' => $staff->id, 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'rating_period_id' => $first->id,
        ]);
        $this->makeIndicator($early, 'core', [
            'progress_pct' => 20, 'progress_status' => 'ongoing', 'rating_period_id' => $first->id,
        ]);

        $later = $this->makeForm([
            'user_id' => $staff->id, 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'rating_period_id' => $second->id,
        ]);
        $this->makeIndicator($later, 'core', [
            'progress_pct' => 100, 'progress_status' => 'completed', 'rating_period_id' => $second->id,
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/my-team/progress?school_year_id={$year->id}&rating_period_id={$first->id}")
            ->assertOk()
            ->assertJsonPath('rows.0.progress_pct', 20);
    }

    public function test_a_director_without_a_head_role_still_sees_their_staff(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);
        $office = $this->makeUnit(['name' => 'Center for ICT', 'code' => 'CICT', 'type' => 'office']);
        $director = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        OrgUnitReviewer::create([
            'org_unit_id' => $office->id,
            'slot'        => 'director_review',
            'user_id'     => $director->id,
        ]);

        $form = $this->makeForm([
            'user_id' => $staff->id, 'org_unit_id' => $office->id,
            'school_year_id' => $year->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($form, 'core', [
            'progress_pct' => 55, 'progress_status' => 'ongoing', 'rating_period_id' => $period->id,
        ]);

        $this->actingAsUser($director);

        $this->getJson("/api/my-team/progress?school_year_id={$year->id}&rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('view', 'people')
            ->assertJsonPath('totals.people', 1)
            ->assertJsonPath('rows.0.id', $staff->id)
            ->assertJsonPath('rows.0.progress_pct', 55);
    }

    public function test_an_employee_may_not_read_team_progress(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $staff = User::factory()->create(['role' => 'employee']);

        $this->actingAsUser($staff);

        $this->getJson("/api/my-team/progress?school_year_id={$year->id}")->assertStatus(403);
    }
}
