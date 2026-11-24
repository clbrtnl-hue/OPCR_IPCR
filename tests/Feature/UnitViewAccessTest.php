<?php

namespace Tests\Feature;

use App\Models\OrgUnitReviewer;
use App\Models\OrgUnitViewer;
use App\Models\User;
use App\Services\PcrWorkflow;
use Tests\PmsTestCase;

/**
 * Can view forms lets several people open a unit and the departments under it.
 * It does not put them on the review chain.
 */
class UnitViewAccessTest extends PmsTestCase
{
    public function test_viewers_can_open_a_unit_and_its_departments_without_reviewing(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'College of Information Technology', 'code' => 'CIT', 'type' => 'office']);
        $department = $this->makeUnit([
            'name' => 'Department of Information Technology', 'code' => 'DIT',
            'type' => 'program', 'parent_id' => $college->id,
        ]);
        $year = $this->makeSchoolYear();
        $this->makePeriod($year);

        $head    = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $department->id]);
        $department->update(['head_user_id' => $head->id]);

        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $department->id]);
        $first   = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $college->id]);
        $second  = User::factory()->create(['role' => 'employee']);

        foreach ([$first, $second] as $person) {
            OrgUnitViewer::create(['org_unit_id' => $college->id, 'user_id' => $person->id]);
        }

        $form = $this->makeForm([
            'org_unit_id' => $department->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        $fresh = $form->fresh();
        PcrWorkflow::resolveReviewers($fresh);
        $this->assertNull($fresh->resolvedReviewerMap['director_review'] ?? null);
        $this->assertSame(0, OrgUnitReviewer::where('user_id', $first->id)->count());

        $form->forceFill(['status' => 'head_review', 'head_reviewer_id' => $head->id])->save();

        foreach ([$first, $second] as $person) {
            $this->actingAsUser($person);

            $this->getJson("/api/pcr-forms/{$form->id}")->assertOk();
            $this->getJson('/api/my-team')
                ->assertOk()
                ->assertJsonFragment(['id' => $faculty->id]);
            $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'dean_review'])
                ->assertStatus(403);
        }
    }
}
