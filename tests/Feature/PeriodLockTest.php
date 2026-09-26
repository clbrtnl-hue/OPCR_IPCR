<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\PmsTestCase;

/**
 * The cut-off. A locked rating period is finished. It is view-only for everyone
 * but an administrator: no edits, no assignments, no accomplishments, no
 * evidence, no progress, no rating.
 */
class PeriodLockTest extends PmsTestCase
{
    private function lockedScenario(): array
    {
        $this->makeOrganization();
        $unit   = $this->makeUnit();
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $employee  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $form      = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);
        $indicator = $this->makeIndicator($form, 'core');

        return compact('unit', 'year', 'period', 'employee', 'form', 'indicator');
    }

    public function test_an_admin_locks_and_unlocks_a_period(): void
    {
        ['period' => $period] = $this->lockedScenario();

        $this->actingAsRole('admin');

        $this->postJson("/api/rating-periods/{$period->id}/lock", ['locked' => true])->assertStatus(200);
        $this->assertTrue((bool) $period->fresh()->is_locked);

        // Locking twice is a no-op worth reporting rather than silently repeating.
        $this->postJson("/api/rating-periods/{$period->id}/lock", ['locked' => true])->assertStatus(409);

        $this->postJson("/api/rating-periods/{$period->id}/lock", ['locked' => false])->assertStatus(200);
        $this->assertFalse((bool) $period->fresh()->is_locked);
    }

    public function test_only_an_admin_may_lock(): void
    {
        ['period' => $period] = $this->lockedScenario();

        $this->actingAsRole('qa');

        $this->postJson("/api/rating-periods/{$period->id}/lock", ['locked' => true])->assertStatus(403);
    }

    public function test_a_locked_period_refuses_accomplishments_and_evidence(): void
    {
        ['period' => $period, 'employee' => $employee, 'indicator' => $indicator] = $this->lockedScenario();

        $period->update(['is_locked' => true]);

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'Delivered.',
        ])->assertStatus(409);

        $this->post('/api/pcr-attachments', [
            'indicator_id'     => $indicator->id,
            'rating_period_id' => $period->id,
            'file'             => UploadedFile::fake()->image('evidence.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_a_locked_period_refuses_progress_and_rating(): void
    {
        ['period' => $period, 'employee' => $employee, 'indicator' => $indicator, 'form' => $form]
            = $this->lockedScenario();

        $period->update(['is_locked' => true]);

        $this->actingAsUser($employee);
        $this->postJson("/api/pcr-indicators/{$indicator->id}/progress", [
            'progress_status' => 'ongoing', 'progress_pct' => 40,
        ])->assertStatus(409);

        $form->update(['status' => 'qa_rating']);
        $this->actingAsRole('qa');

        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5]],
        ])->assertStatus(409);
    }

    public function test_a_locked_period_refuses_edits_and_assignments(): void
    {
        ['period' => $period, 'unit' => $unit, 'year' => $year, 'employee' => $employee] = $this->lockedScenario();

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $head->id,
            'rating_period_id' => $period->id,
        ]);
        $indicator = $this->makeIndicator($form, 'core');

        $period->update(['is_locked' => true]);

        $this->actingAsUser($head);

        $this->postJson('/api/pcr-indicators', [
            'id'          => $indicator->id,
            'output_id'   => $indicator->output_id,
            'description' => 'Changed after the cut-off.',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Mid-year Review is locked. It is view-only now — ask an administrator to unlock it.');

        $this->postJson("/api/pcr-indicators/{$indicator->id}/assign", [
            'user_ids' => [$employee->id],
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Mid-year Review is locked. It is view-only now — ask an administrator to unlock it.');

        $this->assertDatabaseMissing('pcr_target_assignments', [
            'indicator_id' => $indicator->id,
            'user_id'      => $employee->id,
        ]);
    }

    public function test_locking_one_period_leaves_the_other_writable(): void
    {
        ['period' => $first, 'unit' => $unit, 'year' => $year] = $this->lockedScenario();

        $second = $this->makePeriod($year, 2);
        $first->update(['is_locked' => true, 'is_active' => false]);
        $second->update(['is_active' => true, 'is_locked' => false]);

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $head->id,
            'rating_period_id' => $second->id,
        ]);
        $indicator = $this->makeIndicator($form, 'core');

        $this->actingAsUser($head);

        $this->postJson('/api/pcr-indicators', [
            'id'          => $indicator->id,
            'output_id'   => $indicator->output_id,
            'description' => 'Still open in the second period.',
        ])->assertSuccessful();
    }

    public function test_an_admin_still_works_through_a_lock(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->lockedScenario();

        $period->update(['is_locked' => true]);

        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'Corrected after the cut-off.',
        ])->assertSuccessful();

        $this->postJson('/api/pcr-indicators', [
            'id'          => $indicator->id,
            'output_id'   => $indicator->output_id,
            'description' => 'Corrected after the cut-off.',
        ])->assertSuccessful();
    }
}
