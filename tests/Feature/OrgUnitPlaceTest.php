<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/** Dragging a unit saves its parent and its order among the units beside it. */
class OrgUnitPlaceTest extends PmsTestCase
{
    public function test_a_unit_can_move_beside_another_unit(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $first = $this->makeUnit([
            'name' => 'Student Affairs', 'code' => 'SAS', 'type' => 'office', 'parent_id' => $college->id, 'sort_order' => 1,
        ]);
        $second = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id, 'sort_order' => 2,
        ]);
        $third = $this->makeUnit([
            'name' => 'Administration', 'code' => 'AFS', 'type' => 'office', 'parent_id' => $college->id, 'sort_order' => 3,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/org-units/{$third->id}/place", [
            'parent_id' => $college->id,
            'before_id' => $first->id,
        ])->assertOk();

        $order = $college->children()->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame(['Administration', 'Student Affairs', 'Academic Affairs'], $order);
        $this->assertSame($college->id, (int) $second->fresh()->parent_id);
    }

    public function test_a_unit_can_move_under_another_unit(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id, 'sort_order' => 1,
        ]);
        $office = $this->makeUnit([
            'name' => 'Registrar', 'code' => 'REG', 'type' => 'office', 'parent_id' => $college->id, 'sort_order' => 2,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/org-units/{$office->id}/place", [
            'parent_id' => $affairs->id,
            'before_id' => null,
        ])->assertOk();

        $this->assertSame($affairs->id, (int) $office->fresh()->parent_id);
        $this->assertSame(1, (int) $office->fresh()->sort_order);
    }

    public function test_a_unit_cannot_move_under_its_own_child(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/org-units/{$college->id}/place", [
            'parent_id' => $affairs->id,
            'before_id' => null,
        ])->assertStatus(422);

        $this->assertNull($college->fresh()->parent_id);
    }

    public function test_a_new_unit_is_placed_at_the_end_of_its_parent(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college', 'sort_order' => 1]);
        $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $college->id, 'sort_order' => 4,
        ]);

        $this->actingAsUser(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/org-units', [
            'name'      => 'Registrar',
            'code'      => 'REG',
            'type'      => 'office',
            'parent_id' => $college->id,
        ])->assertCreated();

        $this->assertSame(5, (int) $college->children()->where('code', 'REG')->value('sort_order'));
    }
}
