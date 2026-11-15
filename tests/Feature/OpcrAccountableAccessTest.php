<?php

namespace Tests\Feature;

use App\Models\PcrTargetAssignment;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * People named in the OPCR Accountable column can open that form from their
 * own account. They do not gain the president's pen.
 */
class OpcrAccountableAccessTest extends PmsTestCase
{
    public function test_people_named_on_the_opcr_can_open_it_and_cannot_edit_it(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);

        $office = $this->makeUnit([
            'name' => 'College of Arts and Sciences', 'code' => 'CAS',
            'type' => 'program', 'parent_id' => $college->id,
        ]);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $college->id]);
        $dean      = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $office->id]);
        $vp        = User::factory()->create(['role' => 'vp', 'org_unit_id' => $office->id]);
        $director  = User::factory()->create(['role' => 'qa', 'org_unit_id' => $office->id]);
        $outsider  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id,
            'school_year_id' => $year->id, 'status' => 'published', 'user_id' => $president->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        foreach ([$dean, $vp, $director] as $person) {
            PcrTargetAssignment::create([
                'indicator_id'     => $target->id,
                'user_id'          => $person->id,
                'assigned_by'      => $president->id,
                'assigned_by_name' => $president->name,
                'rating_period_id' => $period->id,
            ]);
        }

        foreach ([$dean, $vp, $director] as $person) {
            $this->actingAsUser($person);

            $this->getJson('/api/pcr-forms?accountable=1')
                ->assertOk()
                ->assertJsonPath('0.id', $opcr->id);

            $this->getJson("/api/pcr-forms?type=opcr&school_year_id={$year->id}")
                ->assertOk()
                ->assertJsonFragment(['id' => $opcr->id]);

            $this->getJson("/api/pcr-forms/{$opcr->id}")->assertOk();

            $this->postJson('/api/pcr-indicators', [
                'id'          => $target->id,
                'output_id'   => $target->output_id,
                'description' => 'Rewritten by someone who only reads the form.',
            ])->assertStatus(409);

            $this->assertStringNotContainsString(
                'Rewritten by someone who only reads the form.',
                (string) $target->fresh()->description
            );
        }

        $this->actingAsUser($outsider);

        $this->getJson('/api/pcr-forms?accountable=1')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/pcr-forms?type=opcr&school_year_id={$year->id}")->assertOk()->assertJsonCount(0);
        $this->getJson("/api/pcr-forms/{$opcr->id}")->assertStatus(403);
    }

    public function test_qa_can_open_the_college_opcr_without_being_named(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $qa      = User::factory()->create(['role' => 'qa', 'org_unit_id' => $college->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id,
            'school_year_id' => $year->id, 'status' => 'draft',
        ]);

        $this->actingAsUser($qa);

        $this->getJson('/api/pcr-forms?accountable=1')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/pcr-forms?type=opcr&school_year_id={$year->id}")
            ->assertOk()
            ->assertJsonPath('0.id', $opcr->id);
        $this->getJson("/api/pcr-forms/{$opcr->id}")->assertOk();
    }
}
