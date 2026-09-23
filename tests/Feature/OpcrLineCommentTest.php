<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

class OpcrLineCommentTest extends PmsTestCase
{
    public function test_the_president_comments_on_an_opcr_line(): void
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $line = $this->makeIndicator($opcr, 'core');

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);

        $this->actingAsUser($president);

        $this->postJson('/api/pcr-comments', [
            'form_id' => $opcr->id, 'indicator_id' => $line->id, 'body' => '<p>Please attach the exam results.</p>',
        ])->assertCreated();

        $this->actingAsRole('qa');

        $this->getJson("/api/pcr-forms/{$opcr->id}/comments")
            ->assertOk()
            ->assertJsonPath('0.indicator_id', $line->id)
            ->assertJsonPath('0.author_role', 'president');
    }
}
