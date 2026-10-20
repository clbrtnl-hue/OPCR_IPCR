<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * The People column on a parent office includes everyone in the units under
 * it. Those people stay members of their own unit, so their review chain
 * does not move up to the parent.
 */
class OrgUnitPeopleCountTest extends PmsTestCase
{
    public function test_a_parent_office_counts_the_people_under_it(): void
    {
        $this->makeOrganization();

        $affairs = $this->makeUnit(['name' => 'Academic Affairs and Services', 'code' => 'AAS', 'type' => 'office']);
        $cit     = $this->makeUnit(['name' => 'CIT', 'code' => 'CIT', 'parent_id' => $affairs->id]);
        $cba     = $this->makeUnit(['name' => 'CBA', 'code' => 'CBA', 'parent_id' => $affairs->id]);
        $program = $this->makeUnit(['name' => 'BS Information Technology', 'code' => 'BSIT', 'parent_id' => $cit->id]);
        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG']);

        User::factory()->create(['role' => 'program_head', 'org_unit_id' => $affairs->id]);
        User::factory()->count(2)->create(['role' => 'employee', 'org_unit_id' => $cit->id]);
        User::factory()->count(3)->create(['role' => 'employee', 'org_unit_id' => $cba->id]);
        User::factory()->count(4)->create(['role' => 'employee', 'org_unit_id' => $program->id]);
        User::factory()->create(['role' => 'employee', 'org_unit_id' => $elsewhere->id]);

        $admin = User::factory()->create(['role' => 'admin', 'org_unit_id' => $affairs->id]);
        $this->actingAsUser($admin);

        $counts = collect($this->getJson('/api/org-units')->assertOk()->json())
            ->pluck('members_count', 'id');

        // Two people sit on the office itself. The rest stay on CIT, CBA, and the program under CIT.
        $this->assertSame(2 + 2 + 3 + 4, $counts[$affairs->id]);
        $this->assertSame(2 + 4, $counts[$cit->id]);
        $this->assertSame(3, $counts[$cba->id]);
        $this->assertSame(4, $counts[$program->id]);
        $this->assertSame(1, $counts[$elsewhere->id]);
    }
}
