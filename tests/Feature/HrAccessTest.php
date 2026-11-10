<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * Human Resource reads the whole college and the personal record, and acts
 * only on the forms that name them — the staff of the HR office.
 */
class HrAccessTest extends PmsTestCase
{
    private function office(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $vp      = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $hr      = User::factory()->create(['role' => 'hr']);
        $hrmo    = $this->makeUnit([
            'name'         => 'Human Resource Management Office',
            'code'         => 'AFS-HRMO',
            'type'         => 'office',
            'parent_id'    => $college->id,
            'head_user_id' => $hr->id,
            'vp_user_id'   => $vp->id,
        ]);
        $hr->update(['org_unit_id' => $hrmo->id]);

        $staff = User::factory()->create([
            'role' => 'employee', 'org_unit_id' => $hrmo->id, 'name' => 'HRMO Staff',
        ]);
        $faculty = User::factory()->create(['role' => 'employee', 'name' => 'Faculty']);

        $own = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $hrmo->id,
            'user_id'        => $hr->id,
            'status'         => 'draft',
        ]);
        $staffForm = $this->makeForm([
            'school_year_id'   => $year->id,
            'org_unit_id'      => $hrmo->id,
            'user_id'          => $staff->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $hr->id,
        ]);
        $other = $this->makeForm([
            'school_year_id'   => $year->id,
            'org_unit_id'      => $college->id,
            'user_id'          => $faculty->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $vp->id,
        ]);

        return compact('year', 'hr', 'staff', 'faculty', 'own', 'staffForm', 'other');
    }

    public function test_hr_reads_every_form_and_only_reviews_the_ones_that_name_them(): void
    {
        ['hr' => $hr, 'own' => $own, 'staffForm' => $staffForm, 'other' => $other] = $this->office();

        $this->actingAsUser($hr);

        $all = collect($this->getJson('/api/pcr-forms')->assertOk()->json())->pluck('id');

        $this->assertTrue($all->contains($own->id));
        $this->assertTrue($all->contains($staffForm->id));
        $this->assertTrue($all->contains($other->id));

        $queue = collect($this->getJson('/api/pcr-forms?queue=1')->assertOk()->json())->pluck('id');

        $this->assertTrue($queue->contains($staffForm->id));
        $this->assertFalse($queue->contains($other->id));
        $this->assertFalse($queue->contains($own->id));

        $this->getJson("/api/pcr-forms/{$other->id}")->assertOk();
    }

    public function test_an_employee_cannot_read_another_offices_form(): void
    {
        ['faculty' => $faculty, 'staffForm' => $staffForm] = $this->office();

        $this->actingAsUser($faculty);

        $this->getJson("/api/pcr-forms/{$staffForm->id}")->assertStatus(403);
    }

    public function test_hr_can_open_the_rating_summary_and_a_personal_record(): void
    {
        ['year' => $year, 'hr' => $hr, 'faculty' => $faculty] = $this->office();

        $this->actingAsUser($hr);

        $this->getJson('/api/reports/summary?school_year_id=' . $year->id)->assertOk();
        $this->getJson("/api/people/{$faculty->id}")
            ->assertOk()
            ->assertJsonPath('may_view_sheet', true);
    }

    public function test_hr_does_not_run_setup_or_remark_on_someone_elses_form(): void
    {
        ['hr' => $hr, 'own' => $own, 'other' => $other] = $this->office();

        $this->actingAsUser($hr);

        $this->getJson('/api/users')->assertStatus(403);

        $this->postJson('/api/pcr-comments', [
            'form_id' => $other->id,
            'body'    => 'Please complete this.',
        ])->assertStatus(403);

        $this->postJson('/api/pcr-comments', [
            'form_id' => $own->id,
            'body'    => 'My own note.',
        ])->assertStatus(201);

        $this->postJson("/api/pcr-forms/{$other->id}/status", ['status' => 'vp_review'])
            ->assertStatus(403);
    }
};
