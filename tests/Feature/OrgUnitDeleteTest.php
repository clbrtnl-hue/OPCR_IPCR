<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\User;
use Tests\PmsTestCase;

/** Deleting a unit moves its people, sub-units, and forms up to the parent. */
class OrgUnitDeleteTest extends PmsTestCase
{
    public function test_people_move_up_when_their_unit_is_deleted(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $office = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id,
        ]);
        $program = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'type' => 'program', 'parent_id' => $office->id,
        ]);
        $year = $this->makeSchoolYear();
        $person = User::factory()->create(['org_unit_id' => $office->id]);
        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $office->id,
            'user_id'        => $person->id,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/org-units/{$office->id}")->assertOk();

        $this->assertNull(OrgUnit::find($office->id));
        $this->assertSame($college->id, (int) $person->fresh()->org_unit_id);
        $this->assertSame($college->id, (int) $program->fresh()->parent_id);
        $this->assertSame($college->id, (int) $form->fresh()->org_unit_id);
    }

    public function test_the_top_unit_stays_while_people_still_belong_to_it(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        User::factory()->create(['org_unit_id' => $college->id]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/org-units/{$college->id}")->assertStatus(409);

        $this->assertNotNull(OrgUnit::find($college->id));
    }
}
