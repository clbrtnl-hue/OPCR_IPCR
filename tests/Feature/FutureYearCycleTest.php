<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use Tests\PmsTestCase;

/**
 * A school year created ahead can hold a form. Commitments and the review
 * flow stay closed until that year is the current cycle and its period is open.
 */
class FutureYearCycleTest extends PmsTestCase
{
    public function test_an_ipcr_can_be_opened_but_not_written_until_the_year_is_active_and_the_period_is_open(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear([
            'label'     => 'SY 2028-2029',
            'is_active' => false,
        ]);
        $period = $this->makePeriod($year, 1, 'upcoming');
        $period->update(['is_active' => false]);

        $owner = $this->actingAsRole('employee', ['org_unit_id' => $unit->id]);

        $created = $this->postJson('/api/pcr-forms', [
            'type'             => 'ipcr',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'rating_period_id' => $period->id,
        ])->assertStatus(201);

        $formId = $created->json('form.id');

        $this->getJson("/api/pcr-forms/{$formId}")
            ->assertOk()
            ->assertJsonPath(
                'cycle_closed',
                'SY 2028-2029 is not the current cycle yet. Open the review period and set the year active before writing commitments or moving the form.'
            );

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $formId,
            'section' => 'core',
            'title'   => 'Instruction',
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'SY 2028-2029 is not the current cycle yet. Open the review period and set the year active before writing commitments or moving the form.'
            );

        $this->postJson("/api/pcr-forms/{$formId}/status", ['status' => 'commitment_review'])
            ->assertStatus(409);

        $year->update(['is_active' => true]);

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $formId,
            'section' => 'core',
            'title'   => 'Instruction',
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Mid-year Review is still upcoming. Open it before writing commitments or moving the form.'
            );

        $period->update(['status' => 'open']);

        $output = $this->postJson('/api/pcr-outputs', [
            'form_id' => $formId,
            'section' => 'core',
            'title'   => 'Instruction',
        ])->assertStatus(201);

        $this->postJson('/api/pcr-indicators', [
            'output_id'        => $output->json('output.id'),
            'description'      => 'Handle 4 classes from August to December.',
            'rating_period_id' => $period->id,
        ])->assertStatus(201);

        $this->getJson("/api/pcr-forms/{$formId}")
            ->assertOk()
            ->assertJsonPath('cycle_closed', null);

        $this->assertSame($owner->id, (int) PcrForm::find($formId)->user_id);
    }

    public function test_an_opcr_stays_closed_until_the_year_is_set_active_and_a_period_is_open(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear([
            'label'     => 'SY 2028-2029',
            'is_active' => false,
        ]);
        $period = $this->makePeriod($year, 1, 'upcoming');
        $period->update(['is_active' => false]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $created = $this->postJson('/api/pcr-forms', [
            'type'           => 'opcr',
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ])->assertStatus(201);

        $formId = $created->json('form.id');

        $this->postJson('/api/pcr-outputs', [
            'form_id' => $formId,
            'section' => 'core',
            'title'   => 'Instruction',
        ])->assertStatus(409);

        $this->postJson("/api/pcr-forms/{$formId}/status", ['status' => 'qa_approval'])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'SY 2028-2029 is not the current cycle yet. Open the review period and set the year active before writing commitments or moving the form.'
            );

        $period->update(['status' => 'open']);

        $this->postJson("/api/pcr-forms/{$formId}/status", ['status' => 'qa_approval'])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'SY 2028-2029 is not the current cycle yet. Open the review period and set the year active before writing commitments or moving the form.'
            );

        $year->update(['is_active' => true]);

        $output = $this->postJson('/api/pcr-outputs', [
            'form_id' => $formId,
            'section' => 'core',
            'title'   => 'Instruction',
        ])->assertStatus(201);

        $this->postJson('/api/pcr-indicators', [
            'output_id'   => $output->json('output.id'),
            'description' => 'Graduate 200 students by July.',
        ])->assertStatus(201);

        $this->postJson("/api/pcr-forms/{$formId}/status", ['status' => 'qa_approval'])
            ->assertOk()
            ->assertJsonPath('form.status', 'qa_approval');
    }
}
