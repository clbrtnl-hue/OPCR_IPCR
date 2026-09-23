<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use Tests\PmsTestCase;

/**
 * A (Target + Measure) line is a task with a date to hit. Delay is derived from
 * that date on every read, so it can never go stale, and a finished task is
 * judged on when it was delivered rather than on today's date.
 */
class IndicatorDelayTest extends PmsTestCase
{
    private function indicator(array $attributes = []): PcrIndicator
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $form = $this->makeForm(['org_unit_id' => $unit->id, 'school_year_id' => $year->id]);

        $indicator = $this->makeIndicator($form, 'core');
        $indicator->update($attributes);

        return $indicator->fresh();
    }

    public function test_a_line_with_no_date_reports_no_date(): void
    {
        $this->assertSame('no_date', $this->indicator()->delay['state']);
    }

    public function test_it_counts_days_overdue(): void
    {
        $delay = $this->indicator(['target_date' => now()->subDays(5)->toDateString()])->delay;

        $this->assertSame('overdue', $delay['state']);
        $this->assertSame(5, $delay['days']);
        $this->assertStringContainsString('Overdue by 5', $delay['label']);
    }

    public function test_it_warns_when_a_task_is_due_soon(): void
    {
        $this->assertSame('due_soon', $this->indicator(['target_date' => now()->addDays(3)->toDateString()])->delay['state']);
        $this->assertSame('on_track', $this->indicator(['target_date' => now()->addDays(30)->toDateString()])->delay['state']);
    }

    public function test_a_finished_task_is_judged_on_when_it_was_delivered(): void
    {
        $late = $this->indicator([
            'target_date'     => now()->subDays(10)->toDateString(),
            'completed_on'    => now()->subDays(4)->toDateString(),
            'progress_status' => 'completed',
        ])->delay;

        $this->assertSame('late', $late['state']);
        $this->assertSame(6, $late['days']);

        $onTime = $this->indicator([
            'target_date'     => now()->subDays(10)->toDateString(),
            'completed_on'    => now()->subDays(12)->toDateString(),
            'progress_status' => 'completed',
        ])->delay;

        $this->assertSame('on_time', $onTime['state']);
    }

    public function test_completing_a_task_stamps_the_delivery_date(): void
    {
        $indicator = $this->indicator(['target_date' => now()->addDay()->toDateString()]);
        $owner     = $indicator->output->form->owner
            ?? $this->actingAsRole('admin');

        $this->actingAsRole('admin');

        $this->postJson("/api/pcr-indicators/{$indicator->id}/progress", [
            'progress_status' => 'completed',
        ])->assertSuccessful();

        $this->assertNotNull($indicator->fresh()->completed_on);

        // Reopening it clears the stamp, so it is not judged against a stale date.
        $this->postJson("/api/pcr-indicators/{$indicator->id}/progress", [
            'progress_status' => 'ongoing', 'progress_pct' => 50,
        ])->assertSuccessful();

        $this->assertNull($indicator->fresh()->completed_on);
    }

    public function test_the_target_date_is_saved_with_the_line(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $form = $this->makeForm(['org_unit_id' => $unit->id, 'school_year_id' => $year->id]);
        $output = $this->makeIndicator($form, 'core')->output;

        $this->actingAsRole('admin');

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'   => $output->id,
            'description' => 'Publish 25 peer-reviewed researches.',
            'target_date' => '2026-06-30',
        ])->assertStatus(201);

        $this->assertSame('2026-06-30', PcrIndicator::find($response->json('indicator.id'))
            ->target_date->toDateString());
    }
}
