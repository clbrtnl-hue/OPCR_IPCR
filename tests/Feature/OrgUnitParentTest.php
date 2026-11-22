<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/** An existing unit can move to another parent, but not under itself or a child. */
class OrgUnitParentTest extends PmsTestCase
{
    public function test_an_existing_unit_can_move_to_another_parent(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id,
        ]);
        $cit = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'type' => 'program', 'parent_id' => $affairs->id,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/org-units', [
            'id'        => $cit->id,
            'name'      => $cit->name,
            'code'      => $cit->code,
            'type'      => $cit->type,
            'parent_id' => $college->id,
        ])->assertOk();

        $this->assertSame($college->id, (int) $cit->fresh()->parent_id);
    }

    public function test_a_unit_cannot_move_under_its_own_child(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id,
        ]);
        $cit = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'type' => 'program', 'parent_id' => $affairs->id,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/org-units', [
            'id'        => $college->id,
            'name'      => $college->name,
            'code'      => $college->code,
            'type'      => $college->type,
            'parent_id' => $cit->id,
        ])->assertStatus(422);

        $this->assertNull($college->fresh()->parent_id);
        $this->assertSame($affairs->id, $cit->fresh()->parent_id);
    }
}
