<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * An IPCR is filed twice a year — one form per rating period, each running its
 * own review chain — while the OPCR still covers the whole year. The form's
 * period decides where every one of its lines sits.
 */
class IpcrPeriodFormTest extends PmsTestCase
{
    private function cycle(): array
    {
        $this->makeOrganization();

        $unit    = $this->makeUnit();
        $year    = $this->makeSchoolYear();
        $period1 = $this->makePeriod($year, 1);
        $period2 = $this->makePeriod($year, 2);

        return compact('unit', 'year', 'period1', 'period2');
    }

    public function test_an_ipcr_lands_on_the_active_period_when_none_is_named(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1] = $this->cycle();

        $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $response = $this->postJson('/api/pcr-forms', [
            'type'           => 'ipcr',
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ])->assertStatus(201);

        $this->assertSame($period1->id, (int) $response->json('form.rating_period_id'));
    }

    public function test_a_named_period_is_kept_and_a_foreign_one_refused(): void
    {
        ['unit' => $unit, 'year' => $year, 'period2' => $period2] = $this->cycle();

        $otherYear   = $this->makeSchoolYear(['label' => 'SY 2027-2028', 'is_active' => false]);
        $otherPeriod = $this->makePeriod($otherYear, 1);

        $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $response = $this->postJson('/api/pcr-forms', [
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period2->id,
        ])->assertStatus(201);

        $this->assertSame($period2->id, (int) $response->json('form.rating_period_id'));

        $this->postJson('/api/pcr-forms', [
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $otherPeriod->id,
        ])->assertStatus(422);
    }

    public function test_one_ipcr_per_period_two_per_year(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1, 'period2' => $period2] = $this->cycle();

        $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/pcr-forms', [
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period1->id,
        ])->assertStatus(201);

        $this->postJson('/api/pcr-forms', [
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period2->id,
        ])->assertStatus(201);

        $duplicate = $this->postJson('/api/pcr-forms', [
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period1->id,
        ])->assertStatus(409);

        $this->assertNotNull($duplicate->json('form_id'));
        $this->assertSame(2, PcrForm::where('type', 'ipcr')->count());
    }

    public function test_an_opcr_covers_the_year_even_when_a_period_is_sent(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1] = $this->cycle();

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $response = $this->postJson('/api/pcr-forms', [
            'type'             => 'opcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period1->id,
        ])->assertStatus(201);

        $this->assertNull($response->json('form.rating_period_id'));
    }

    public function test_a_line_inherits_the_forms_period_and_refuses_another(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1, 'period2' => $period2] = $this->cycle();

        $owner = $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period1->id,
        ]);

        $output = $form->outputs()->create(['section' => 'support', 'title' => 'Extension']);

        $line = $this->postJson('/api/pcr-indicators', [
            'output_id'   => $output->id,
            'description' => 'Run 2 community trainings.',
        ])->assertStatus(201);

        $this->assertSame($period1->id, (int) $line->json('indicator.rating_period_id'));

        $this->postJson('/api/pcr-indicators', [
            'output_id'        => $output->id,
            'description'      => 'Run 2 community trainings.',
            'rating_period_id' => $period2->id,
        ])->assertStatus(422);
    }

    public function test_a_line_may_only_link_a_target_in_its_own_period(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1, 'period2' => $period2] = $this->cycle();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['rating_period_id' => $period1->id]);

        $owner = $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $matching = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period1->id,
        ]);
        $matchingOutput = $matching->outputs()->create([
            'section' => 'core', 'title' => 'Research', 'parent_output_id' => $target->output_id,
        ]);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $matchingOutput->id,
            'description'         => 'Publish 3 of the 25 articles.',
            'parent_indicator_id' => $target->id,
        ])->assertStatus(201);

        $mismatched = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period2->id,
        ]);
        $mismatchedOutput = $mismatched->outputs()->create([
            'section' => 'core', 'title' => 'Research', 'parent_output_id' => $target->output_id,
        ]);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $mismatchedOutput->id,
            'description'         => 'Publish 3 of the 25 articles.',
            'parent_indicator_id' => $target->id,
        ])->assertStatus(422);
    }

    public function test_the_two_period_forms_run_independent_chains(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1, 'period2' => $period2] = $this->cycle();

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);

        $owner = $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $first = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period1->id,
        ]);
        $second = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period2->id,
        ]);

        $this->makeIndicator($first, 'support', ['rating_period_id' => $period1->id]);
        $this->makeIndicator($second, 'support', ['rating_period_id' => $period2->id]);

        $this->postJson("/api/pcr-forms/{$first->id}/status", ['status' => 'head_review'])
            ->assertSuccessful();

        $this->assertSame('head_review', $first->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_an_accomplishment_naming_the_wrong_period_is_refused(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1, 'period2' => $period2] = $this->cycle();

        $owner = $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period1->id,
        ]);
        $line = $this->makeIndicator($form, 'core', ['rating_period_id' => $period1->id]);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $line->id,
            'rating_period_id'      => $period2->id,
            'actual_accomplishment' => 'Done early.',
        ])->assertStatus(422);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $line->id,
            'rating_period_id'      => $period1->id,
            'actual_accomplishment' => 'Done early.',
        ])->assertSuccessful();
    }

    public function test_finalizing_an_ipcr_against_the_wrong_period_is_refused(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1, 'period2' => $period2] = $this->cycle();

        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period1->id,
            'status'           => 'qa_rating',
        ]);

        $this->actingAsRole('qa');

        $this->postJson("/api/pcr-forms/{$form->id}/finalize-rating", [
            'rating_period_id' => $period2->id,
        ])->assertStatus(422);
    }

    public function test_the_president_sees_every_form_at_any_status(): void
    {
        ['unit' => $unit, 'year' => $year, 'period1' => $period1] = $this->cycle();

        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $draft = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $owner->id,
            'rating_period_id' => $period1->id,
        ]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $listed = $this->getJson('/api/pcr-forms')->assertSuccessful()->json();
        $this->assertContains($draft->id, array_column($listed, 'id'));

        $queued = $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json();
        $this->assertSame([], $queued);
    }
}
