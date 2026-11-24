<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PcrAssignmentService;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/**
 * The president rates the published college OPCR and closes it.
 * Early rating and unfinished work are not refused here — the screen
 * asks for confirmation. A missing score still stops the close.
 */
class OpcrSelfRatingTest extends PmsTestCase
{
    private function publishedOpcr(): array
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = $this->makePeriod($year);

        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'status'         => 'published',
        ]);

        return [$unit, $period, $opcr];
    }

    public function test_the_president_can_score_before_december_and_closes_when_every_target_is_rated(): void
    {
        [$unit, $period, $opcr] = $this->publishedOpcr();
        $done = $this->makeIndicator($opcr, 'core');
        $open = $this->makeIndicator($opcr, 'support');
        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        Carbon::setTestNow('2026-06-15');

        try {
            $this->postJson('/api/pcr-ratings', [
                'form_id'          => $opcr->id,
                'rating_period_id' => $period->id,
                'ratings'          => [
                    ['indicator_id' => $done->id, 'q' => 5, 'e' => 4, 't' => 5, 'remarks' => 'Met the target.'],
                ],
            ])->assertOk();

            $this->assertSame('published', $opcr->fresh()->status);

            $this->postJson("/api/pcr-forms/{$opcr->id}/finalize-rating", [
                'rating_period_id' => $period->id,
            ])->assertStatus(422)
                ->assertJsonPath('message', '1 target still has no rating. Rate every target before finalizing.');

            $this->assertSame('published', $opcr->fresh()->status);

            $this->postJson('/api/pcr-ratings', [
                'form_id'          => $opcr->id,
                'rating_period_id' => $period->id,
                'ratings'          => [
                    ['indicator_id' => $open->id, 'q' => 4, 'e' => 4, 't' => 4],
                ],
            ])->assertOk();

            $this->postJson("/api/pcr-forms/{$opcr->id}/finalize-rating", [
                'rating_period_id' => $period->id,
            ])->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        $fresh = $opcr->fresh();
        $this->assertSame('final', $fresh->status);
        $this->assertSame($president->id, $fresh->rated_by);
    }

    public function test_a_target_with_only_one_score_is_not_finished(): void
    {
        [$unit, $period, $opcr] = $this->publishedOpcr();
        $line = $this->makeIndicator($opcr, 'core');

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $opcr->id,
            'rating_period_id' => $period->id,
            'ratings'          => [
                ['indicator_id' => $line->id, 'q' => 5, 'e' => null, 't' => null],
            ],
        ])->assertOk();

        $this->postJson("/api/pcr-forms/{$opcr->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertStatus(422);

        $this->assertSame('published', $opcr->fresh()->status);
    }

    public function test_the_opcr_cannot_close_while_an_accountable_person_has_no_narrative(): void
    {
        [$unit, $period, $opcr] = $this->publishedOpcr();
        $line = $this->makeIndicator($opcr, 'core');
        $head = User::factory()->create([
            'role' => 'program_head', 'org_unit_id' => $unit->id, 'name' => 'Luis Ramos',
        ]);
        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        app(PcrAssignmentService::class)->assignIndicator($line, $head, $president, $period->id, false);

        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $opcr->id,
            'rating_period_id' => $period->id,
            'ratings'          => [
                ['indicator_id' => $line->id, 'q' => 5, 'e' => 5, 't' => 5],
            ],
        ])->assertOk();

        $this->postJson("/api/pcr-forms/{$opcr->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Luis Ramos still needs a narrative'));

        $this->assertSame('published', $opcr->fresh()->status);

        $child = $this->commitAgainst($line, $head, ['rating_period_id' => $period->id]);

        $this->postJson("/api/pcr-forms/{$opcr->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertStatus(422);

        $this->documentLine($child, false);

        $this->postJson("/api/pcr-forms/{$opcr->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertOk();

        $this->assertSame('final', $opcr->fresh()->status);
    }

    public function test_qa_cannot_score_a_published_opcr(): void
    {
        [$unit, $period, $opcr] = $this->publishedOpcr();
        $line = $this->makeIndicator($opcr, 'core');

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $opcr->id,
            'rating_period_id' => $period->id,
            'ratings'          => [
                ['indicator_id' => $line->id, 'q' => 5, 'e' => 5, 't' => 5],
            ],
        ])->assertStatus(409);

        $this->assertSame('published', $opcr->fresh()->status);
    }
}
