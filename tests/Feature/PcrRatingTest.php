<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrPeriodSummary;
use App\Models\RatingPeriod;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\PmsTestCase;

class PcrRatingTest extends PmsTestCase
{
    private User $qa;

    private PcrForm $form;

    private RatingPeriod $period;

    private function scenario(string $status = 'qa_rating'): void
    {
        $year         = $this->makeSchoolYear();
        $this->period = $this->makePeriod($year);
        $unit         = $this->makeUnit();

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $this->qa = User::factory()->create(['role' => 'qa']);

        $this->form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $employee->id,
            'status'         => $status,
        ]);

        Passport::actingAs($this->qa, [], 'api');
    }

    private function rate(array $rows)
    {
        return $this->postJson('/api/pcr-ratings', [
            'form_id'          => $this->form->id,
            'rating_period_id' => $this->period->id,
            'ratings'          => $rows,
        ]);
    }

    public function test_qa_rating_stores_the_average_of_q_e_t(): void
    {
        $this->scenario();
        $indicator = $this->makeIndicator($this->form);

        $this->rate([
            ['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 3, 'remarks' => 'Target met.'],
        ])->assertOk();

        $this->assertDatabaseHas('pcr_ratings', [
            'indicator_id' => $indicator->id,
            'q'            => 5,
            'e'            => 4,
            't'            => 3,
            'a'            => 4.00,
        ]);
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $this->scenario();
        $indicator = $this->makeIndicator($this->form);

        $this->rate([['indicator_id' => $indicator->id, 'q' => 6, 'e' => 4, 't' => 3]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ratings.0.q');

        $this->rate([['indicator_id' => $indicator->id, 'q' => 0, 'e' => 4, 't' => 3]])
            ->assertStatus(422);
    }

    public function test_rating_is_refused_while_the_form_is_still_with_the_vp(): void
    {
        $this->scenario('vp_review');
        $indicator = $this->makeIndicator($this->form);

        $this->rate([['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This form is not waiting for a rating yet.');
    }

    public function test_an_indicator_from_another_form_cannot_be_rated(): void
    {
        $this->scenario();
        $this->makeIndicator($this->form);

        $otherForm = $this->makeForm([
            'school_year_id' => $this->form->school_year_id,
            'org_unit_id'    => $this->form->org_unit_id,
            'user_id'        => User::factory()->create(['role' => 'employee'])->id,
        ]);
        $foreign = $this->makeIndicator($otherForm);

        $this->rate([['indicator_id' => $foreign->id, 'q' => 5, 'e' => 5, 't' => 5]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One of those indicators is not part of this form.');
    }

    public function test_finalizing_requires_every_indicator_to_be_rated(): void
    {
        $this->scenario();
        $rated   = $this->makeIndicator($this->form);
        $unrated = $this->makeIndicator($this->form, 'support');

        $this->rate([['indicator_id' => $rated->id, 'q' => 5, 'e' => 5, 't' => 5]])->assertOk();

        $this->postJson("/api/pcr-forms/{$this->form->id}/finalize-rating", [
            'rating_period_id' => $this->period->id,
        ])->assertStatus(422);

        $this->assertSame('qa_rating', $this->form->fresh()->status);

        $this->rate([['indicator_id' => $unrated->id, 'q' => 3, 'e' => 3, 't' => 3]])->assertOk();

        $this->postJson("/api/pcr-forms/{$this->form->id}/finalize-rating", [
            'rating_period_id' => $this->period->id,
        ])->assertOk();

        $this->assertSame('rated', $this->form->fresh()->status);
    }

    public function test_final_average_is_the_mean_of_the_rated_sections(): void
    {
        $this->scenario();

        $core    = $this->makeIndicator($this->form, 'core');
        $support = $this->makeIndicator($this->form, 'support');

        $this->rate([
            ['indicator_id' => $core->id, 'q' => 5, 'e' => 5, 't' => 5],
            ['indicator_id' => $support->id, 'q' => 3, 'e' => 3, 't' => 3],
        ])->assertOk();

        $response = $this->postJson("/api/pcr-forms/{$this->form->id}/finalize-rating", [
            'rating_period_id' => $this->period->id,
        ])->assertOk();

        $this->assertEquals(5.0, $response->json('summary.core_average'));
        $this->assertEquals(3.0, $response->json('summary.support_average'));
        $this->assertEquals(4.0, $response->json('summary.final_average'));
        $response->assertJsonPath('summary.adjectival', 'Very Satisfactory');

        $summary = PcrPeriodSummary::where('form_id', $this->form->id)->first();
        $this->assertSame('Very Satisfactory', $summary->adjectival);
        $this->assertNull($summary->strategic_average);
    }

    public function test_finalizing_stamps_the_rater_and_logs_the_result(): void
    {
        $this->scenario();
        $indicator = $this->makeIndicator($this->form);

        $this->rate([['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5]])->assertOk();

        $this->postJson("/api/pcr-forms/{$this->form->id}/finalize-rating", [
            'rating_period_id' => $this->period->id,
        ])->assertOk();

        $form = $this->form->fresh();
        $this->assertSame($this->qa->name, $form->rated_by_name);
        $this->assertNotNull($form->rated_at);

        $this->assertDatabaseHas('pcr_status_logs', [
            'form_id'     => $form->id,
            'from_status' => 'qa_rating',
            'to_status'   => 'rated',
        ]);
    }

    public function test_re_rating_an_indicator_replaces_the_earlier_score(): void
    {
        $this->scenario();
        $indicator = $this->makeIndicator($this->form);

        $this->rate([['indicator_id' => $indicator->id, 'q' => 2, 'e' => 2, 't' => 2]])->assertOk();
        $this->rate([['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5]])->assertOk();

        $this->assertDatabaseCount('pcr_ratings', 1);
        $this->assertDatabaseHas('pcr_ratings', ['indicator_id' => $indicator->id, 'a' => 5.00]);
    }

    public function test_a_partly_scored_line_averages_only_what_was_given(): void
    {
        $this->scenario();
        $indicator = $this->makeIndicator($this->form);

        $this->rate([['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => null]])->assertOk();

        $this->assertDatabaseHas('pcr_ratings', ['indicator_id' => $indicator->id, 'a' => 4.50]);
    }
}
