<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * A tag is meant to tell you who somebody is, so the card is open to anyone
 * signed in. The Personal Data Sheet behind it is a personal record: yourself,
 * the people who review your work, and those who oversee the cycle.
 */
class UserProfileTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();

        $head   = User::factory()->create(['role' => 'program_head']);
        $office = $this->makeUnit([
            'name' => 'Research and Extension Office', 'code' => 'RES',
            'parent_id' => $college->id, 'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);

        $employee  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);
        $colleague = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        return compact('college', 'office', 'year', 'head', 'employee', 'colleague');
    }

    public function test_anyone_signed_in_sees_the_card(): void
    {
        ['employee' => $employee, 'colleague' => $colleague] = $this->college();

        $this->actingAsUser($colleague);

        $this->getJson("/api/people/{$employee->id}")
            ->assertSuccessful()
            ->assertJsonPath('card.name', $employee->name)
            ->assertJsonPath('may_view_sheet', false)
            ->assertJsonMissingPath('profile');
    }

    public function test_a_colleague_is_refused_the_sheet(): void
    {
        ['employee' => $employee, 'colleague' => $colleague] = $this->college();

        $employee->profile()->create(['tin' => '123-456-789']);

        $this->actingAsUser($colleague);
        $response = $this->getJson("/api/people/{$employee->id}")->assertSuccessful();

        // The identifiers are absent from the payload, not merely hidden.
        $this->assertStringNotContainsString('123-456-789', $response->getContent());
    }

    public function test_you_may_read_your_own_sheet(): void
    {
        ['employee' => $employee] = $this->college();

        $employee->profile()->create(['tin' => '123-456-789']);

        $this->actingAsUser($employee);

        $this->getJson("/api/people/{$employee->id}")
            ->assertSuccessful()
            ->assertJsonPath('may_view_sheet', true)
            ->assertJsonPath('may_edit', true)
            ->assertJsonPath('profile.tin', '123-456-789');
    }

    public function test_a_reviewer_may_read_the_sheet_of_someone_they_review(): void
    {
        ['office' => $office, 'year' => $year, 'head' => $head, 'employee' => $employee] = $this->college();

        $employee->profile()->create(['citizenship' => 'Filipino']);

        // Nothing to review yet, so nothing to read.
        $this->actingAsUser($head);
        $this->getJson("/api/people/{$employee->id}")->assertJsonPath('may_view_sheet', false);

        // Once they are named on that person's form, they may.
        $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id,
            'user_id' => $employee->id, 'head_reviewer_id' => $head->id,
        ]);

        $this->getJson("/api/people/{$employee->id}")
            ->assertJsonPath('may_view_sheet', true)
            ->assertJsonPath('may_edit', false)
            ->assertJsonPath('profile.citizenship', 'Filipino');
    }

    public function test_qa_president_and_admin_may_read_any_sheet(): void
    {
        ['employee' => $employee] = $this->college();

        $employee->profile()->create(['citizenship' => 'Filipino']);

        foreach (['qa', 'president', 'admin'] as $role) {
            $this->actingAsRole($role);

            $this->getJson("/api/people/{$employee->id}")
                ->assertSuccessful()
                ->assertJsonPath('may_view_sheet', true);
        }
    }

    public function test_each_section_can_be_added_edited_and_removed(): void
    {
        ['employee' => $employee] = $this->college();

        $this->actingAsUser($employee);

        $created = $this->postJson('/api/my-profile/educations', [
            'level' => 'college', 'school' => 'Opol Community College', 'degree' => 'BS Information Technology',
        ])->assertStatus(201);

        $id = $created->json('row.id');

        $this->postJson('/api/my-profile/educations', [
            'id' => $id, 'level' => 'college', 'school' => 'Opol Community College',
            'degree' => 'BS Computer Science',
        ])->assertSuccessful();

        $this->assertDatabaseHas('user_educations', ['id' => $id, 'degree' => 'BS Computer Science']);

        $this->deleteJson("/api/my-profile/educations/{$id}")->assertSuccessful();
        $this->assertDatabaseMissing('user_educations', ['id' => $id]);
    }

    public function test_a_section_belongs_to_whoever_is_signed_in(): void
    {
        ['employee' => $employee, 'colleague' => $colleague] = $this->college();

        $this->actingAsUser($employee);
        $id = $this->postJson('/api/my-profile/trainings', ['title' => 'Research writing'])
            ->assertStatus(201)->json('row.id');

        // Somebody else cannot reach into it.
        $this->actingAsUser($colleague);
        $this->deleteJson("/api/my-profile/trainings/{$id}")->assertStatus(404);

        $this->assertDatabaseHas('user_trainings', ['id' => $id]);
    }

    public function test_the_personal_sheet_is_saved_once_per_person(): void
    {
        ['employee' => $employee] = $this->college();

        $this->actingAsUser($employee);

        $this->postJson('/api/my-profile', ['citizenship' => 'Filipino', 'blood_type' => 'O+'])->assertSuccessful();
        $this->postJson('/api/my-profile', ['citizenship' => 'Filipino', 'blood_type' => 'A+'])->assertSuccessful();

        $this->assertDatabaseCount('user_profiles', 1);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $employee->id, 'blood_type' => 'A+']);
    }
}
