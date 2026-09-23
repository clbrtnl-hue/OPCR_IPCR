<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use Tests\PmsTestCase;

/**
 * The two review points carry different commitments, so a success indicator
 * belongs to exactly one of them and is rated only there.
 */
class PcrPeriodIndicatorTest extends PmsTestCase
{
    private function setUpYear(): array
    {
        $this->makeOrganization();

        $unit   = $this->makeUnit();
        $year   = $this->makeSchoolYear();
        $first  = $this->makePeriod($year, 1);
        $second = $this->makePeriod($year, 2);
        $form   = $this->makeForm(['org_unit_id' => $unit->id, 'school_year_id' => $year->id]);

        return compact('unit', 'year', 'first', 'second', 'form');
    }

    public function test_a_line_is_saved_against_the_chosen_period(): void
    {
        ['form' => $form, 'second' => $second] = $this->setUpYear();

        $output = $this->makeIndicator($form, 'core')->output;
        $this->actingAsRole('admin');

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'        => $output->id,
            'description'      => 'Conduct 1 research conference in the second semester.',
            'rating_period_id' => $second->id,
        ])->assertStatus(201);

        $this->assertSame(
            $second->id,
            (int) PcrIndicator::find($response->json('indicator.id'))->rating_period_id
        );
    }

    public function test_a_period_from_another_school_year_is_refused(): void
    {
        ['form' => $form] = $this->setUpYear();

        $otherYear   = $this->makeSchoolYear(['label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $otherPeriod = $this->makePeriod($otherYear, 1);

        $output = $this->makeIndicator($form, 'core')->output;
        $this->actingAsRole('admin');

        $this->postJson('/api/pcr-indicators', [
            'output_id'        => $output->id,
            'description'      => 'Something.',
            'rating_period_id' => $otherPeriod->id,
        ])->assertStatus(422);
    }

    public function test_each_period_keeps_its_own_lines(): void
    {
        ['form' => $form, 'first' => $first, 'second' => $second] = $this->setUpYear();

        $output = $this->makeIndicator($form, 'core', ['rating_period_id' => $first->id])->output;

        PcrIndicator::create([
            'output_id'        => $output->id,
            'rating_period_id' => $second->id,
            'description'      => 'A second-semester commitment.',
        ]);

        $this->actingAsRole('admin');
        $payload = $this->getJson("/api/pcr-forms/{$form->id}")->assertSuccessful()->json();

        $lines = $payload['outputs'][0]['indicators'];

        $this->assertCount(2, $lines);
        $this->assertSame(
            [$first->id, $second->id],
            array_map('intval', array_column($lines, 'rating_period_id'))
        );
    }
}
