<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * "My Forms" and "My IPCR" list only what the signed-in person owns. A head
 * still oversees their unit's forms, but those belong in the review queue —
 * with mine=1 the list must not include the IPCRs of the people under them.
 */
class MyFormsScopeTest extends PmsTestCase
{
    public function test_a_head_sees_their_own_ipcr_and_unit_opcr_but_not_subordinate_ipcrs(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $head    = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);

        $own    = $this->makeForm(['school_year_id' => $year->id, 'org_unit_id' => $unit->id, 'user_id' => $head->id]);
        $theirs = $this->makeForm(['school_year_id' => $year->id, 'org_unit_id' => $unit->id, 'user_id' => $faculty->id]);
        $opcr   = $this->makeForm(['type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $unit->id]);

        $this->actingAsUser($head);

        $ids = array_column($this->getJson('/api/pcr-forms?mine=1')->assertStatus(200)->json(), 'id');

        $this->assertContains($own->id, $ids);
        $this->assertContains($opcr->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_without_mine_a_head_still_sees_the_forms_of_their_unit(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $head    = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);

        $theirs = $this->makeForm(['school_year_id' => $year->id, 'org_unit_id' => $unit->id, 'user_id' => $faculty->id]);

        $this->actingAsUser($head);

        $ids = array_column($this->getJson('/api/pcr-forms')->assertStatus(200)->json(), 'id');

        $this->assertContains($theirs->id, $ids);
    }

    public function test_an_employee_with_mine_sees_only_their_own_forms(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $peer    = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $own   = $this->makeForm(['school_year_id' => $year->id, 'org_unit_id' => $unit->id, 'user_id' => $faculty->id]);
        $peers = $this->makeForm(['school_year_id' => $year->id, 'org_unit_id' => $unit->id, 'user_id' => $peer->id]);
        $opcr  = $this->makeForm(['type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $unit->id]);

        $this->actingAsUser($faculty);

        $ids = array_column($this->getJson('/api/pcr-forms?mine=1')->assertStatus(200)->json(), 'id');

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($peers->id, $ids);
        $this->assertNotContains($opcr->id, $ids);
    }
}
