<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\User;
use Tests\PmsTestCase;

class BulkIpcrTest extends PmsTestCase
{
    private function office(): array
    {
        $this->makeOrganization();

        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year, 1);
        $period2 = $this->makePeriod($year, 2);

        $unit = $this->makeUnit();
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $vp   = User::factory()->create(['role' => 'vp']);

        $unit->update(['head_user_id' => $head->id, 'vp_user_id' => $vp->id]);

        $members = collect(range(1, 3))->map(
            fn () => User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id])
        );

        return compact('year', 'period', 'period2', 'unit', 'head', 'vp', 'members');
    }

    public function test_an_admin_opens_a_shell_for_everyone_in_the_office(): void
    {
        ['year' => $year, 'period' => $period, 'unit' => $unit, 'members' => $members, 'head' => $head] = $this->office();

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('created', 4)
            ->assertJsonPath('skipped', 0);

        foreach ($members->push($head) as $person) {
            $this->assertDatabaseHas('pcr_forms', [
                'type'             => 'ipcr',
                'user_id'          => $person->id,
                'school_year_id'   => $year->id,
                'rating_period_id' => $period->id,
                'status'           => 'draft',
            ]);

            $this->assertDatabaseHas('notifications', ['user_id' => $person->id, 'type' => 'form']);
        }

        $this->assertSame(4, \App\Models\PcrStatusLog::where('to_status', 'draft')->count());
    }

    public function test_the_unnamed_period_is_the_active_one_and_the_other_half_is_separate(): void
    {
        ['year' => $year, 'period' => $period, 'period2' => $period2, 'unit' => $unit] = $this->office();

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertJsonPath('created', 4);

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id, 'rating_period_id' => $period2->id,
        ])->assertJsonPath('created', 4);

        $this->assertSame(4, PcrForm::where('rating_period_id', $period->id)->count());
        $this->assertSame(4, PcrForm::where('rating_period_id', $period2->id)->count());
    }

    public function test_a_second_run_skips_whoever_already_has_one(): void
    {
        ['year' => $year, 'unit' => $unit] = $this->office();

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertJsonPath('created', 4);

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])
            ->assertJsonPath('created', 0)
            ->assertJsonPath('skipped', 4);
    }

    public function test_only_the_named_people_get_one(): void
    {
        ['year' => $year, 'unit' => $unit, 'members' => $members] = $this->office();

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_ids'       => [$members[0]->id],
        ])->assertJsonPath('created', 1);

        $this->assertDatabaseHas('pcr_forms', ['user_id' => $members[0]->id]);
        $this->assertDatabaseMissing('pcr_forms', ['user_id' => $members[1]->id]);
    }

    public function test_the_head_and_vp_may_open_their_units_shells(): void
    {
        ['year' => $year, 'unit' => $unit, 'head' => $head, 'vp' => $vp] = $this->office();

        $this->actingAsUser($head);

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(201);

        $this->actingAsUser($vp);

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(201);
    }

    public function test_a_stranger_cannot_open_someone_elses_office(): void
    {
        ['year' => $year, 'unit' => $unit, 'members' => $members] = $this->office();

        $this->actingAsUser($members[0]);

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(403);

        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG', 'type' => 'office']);
        $outsider  = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $elsewhere->id]);

        $this->actingAsUser($outsider);

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(403);
    }

    public function test_a_period_from_another_year_is_refused(): void
    {
        ['year' => $year, 'unit' => $unit] = $this->office();

        $otherYear = $this->makeSchoolYear([
            'label' => 'SY 2027-2028', 'is_active' => false,
            'start_date' => '2027-08-01', 'end_date' => '2028-07-31',
        ]);
        $otherPeriod = $this->makePeriod($otherYear, 1);

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $otherPeriod->id,
        ])->assertStatus(422);
    }

    public function test_an_empty_office_says_so(): void
    {
        ['year' => $year] = $this->office();

        $empty = $this->makeUnit(['name' => 'Library', 'code' => 'LIB', 'type' => 'office']);

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $empty->id,
        ])->assertStatus(422);
    }

    public function test_inactive_accounts_are_left_out(): void
    {
        ['year' => $year, 'unit' => $unit, 'members' => $members] = $this->office();

        $members[0]->update(['status' => 'inactive']);

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-forms/bulk', [
            'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertJsonPath('created', 3);

        $this->assertDatabaseMissing('pcr_forms', ['user_id' => $members[0]->id]);
    }
}
