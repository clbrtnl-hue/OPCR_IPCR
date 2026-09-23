<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\CurrentOrganization;
use Tests\PmsTestCase;

/**
 * The UI is single-tenant, but the schema is not: one organization must never
 * see another's people, units, cycles or forms, and a new record must inherit
 * its creator's organization without the caller saying so.
 */
class OrganizationScopeTest extends PmsTestCase
{
    public function test_a_new_record_inherits_the_current_organization(): void
    {
        $occ = $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $this->assertSame($occ->id, (int) $unit->organization_id);
        $this->assertSame($occ->id, (int) $year->organization_id);
    }

    public function test_one_organization_cannot_see_anothers_records(): void
    {
        $occ      = $this->makeOrganization();
        $occUnit  = $this->makeUnit(['name' => 'Research and Extension Office']);
        $occAdmin = User::factory()->create(['role' => 'admin', 'org_unit_id' => $occUnit->id]);

        $other      = $this->makeOrganization(['name' => 'Another College', 'code' => 'other']);
        $otherUnit  = $this->makeUnit(['name' => 'Somebody Elses Office']);
        User::factory()->create(['role' => 'employee', 'org_unit_id' => $otherUnit->id]);

        $this->actingAsUser($occAdmin);

        $units = $this->getJson('/api/org-units')->assertStatus(200)->json();
        $names = array_column($units, 'name');

        $this->assertContains('Research and Extension Office', $names);
        $this->assertNotContains('Somebody Elses Office', $names);

        $emails = array_column($this->getJson('/api/users')->assertStatus(200)->json(), 'email');
        $this->assertNotContains(User::where('organization_id', $other->id)->value('email'), $emails);
    }

    public function test_two_organizations_may_share_a_school_year_label(): void
    {
        $this->makeOrganization();
        $this->makeSchoolYear(['label' => '2026']);

        $this->makeOrganization(['name' => 'Another College', 'code' => 'other']);
        $second = $this->makeSchoolYear(['label' => '2026']);

        $this->assertSame('2026', $second->label);
        $this->assertSame(2, SchoolYear::acrossOrganizations()->where('label', '2026')->count());
    }

    public function test_the_scope_can_be_lifted_for_cross_tenant_tooling(): void
    {
        $this->makeOrganization();
        $this->makeUnit(['name' => 'One']);

        $this->makeOrganization(['name' => 'Another College', 'code' => 'other']);
        $this->makeUnit(['name' => 'Two']);

        $this->assertSame(1, \App\Models\OrgUnit::count());
        $this->assertSame(2, \App\Models\OrgUnit::acrossOrganizations()->count());
    }

    public function test_the_rating_trend_stops_at_the_organization_boundary(): void
    {
        $ratedCollege = function (float $average) {
            $unit   = $this->makeUnit();
            $year   = $this->makeSchoolYear();
            $period = $this->makePeriod($year, 1);

            $form = $this->makeForm([
                'type' => 'ipcr', 'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
                'rating_period_id' => $period->id, 'status' => 'rated',
                'user_id' => User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id])->id,
            ]);

            \App\Models\PcrPeriodSummary::create([
                'form_id' => $form->id, 'rating_period_id' => $period->id,
                'final_average' => $average, 'adjectival' => 'Very Satisfactory',
            ]);

            return compact('year');
        };

        $occ = $this->makeOrganization();
        ['year' => $ours] = $ratedCollege(3.0);

        $this->makeOrganization(['name' => 'Another College', 'code' => 'other']);
        $ratedCollege(5.0);

        app(CurrentOrganization::class)->set($occ->id);

        $president = User::factory()->create(['role' => 'president']);
        $this->actingAsUser($president);

        $trend = $this->getJson("/api/reports/summary?school_year_id={$ours->id}")
            ->assertSuccessful()
            ->json('trend');

        $this->assertCount(1, $trend, 'The trend must not reach into another organization.');
        $this->assertEquals(3.0, $trend[0]['average']);
        $this->assertSame(1, $trend[0]['forms']);
    }

    public function test_branding_is_served_from_the_record(): void
    {
        $occ = $this->makeOrganization(['short_name' => 'OCC']);

        $response = $this->getJson('/api/organization');

        $response->assertStatus(200)
            ->assertJsonPath('name', $occ->name)
            ->assertJsonPath('short_name', 'OCC');
    }
}
