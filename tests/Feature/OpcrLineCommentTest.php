<?php

namespace Tests\Feature;

use App\Models\Notification;
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

    public function test_a_remark_on_the_opcr_notifies_the_president_without_a_mention(): void
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $qa        = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'qa_approval',
        ]);
        $line = $this->makeIndicator($opcr, 'core');

        $this->actingAsUser($qa);

        $this->postJson('/api/pcr-comments', [
            'form_id'      => $opcr->id,
            'indicator_id' => $line->id,
            'body'         => '<p>Revise this line.</p>',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $president->id,
            'type'    => 'comment',
            'form_id' => $opcr->id,
        ]);

        $note = Notification::where('user_id', $president->id)->where('type', 'comment')->first();
        $this->assertStringContainsString('Revise this line', $note->body);
        $this->assertSame("/forms/{$opcr->id}?line={$line->id}", $note->link);
    }

    public function test_a_form_level_remark_opens_the_remarks_panel(): void
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $qa        = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'qa_approval',
        ]);

        $this->actingAsUser($qa);

        $this->postJson('/api/pcr-comments', [
            'form_id' => $opcr->id,
            'body'    => '<p>See the remarks.</p>',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $president->id,
            'type'    => 'comment',
            'link'    => "/forms/{$opcr->id}?remarks=1",
        ]);
    }

    public function test_the_president_is_not_notified_of_their_own_opcr_remark(): void
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'qa_approval',
        ]);

        $this->actingAsUser($president);

        $this->postJson('/api/pcr-comments', [
            'form_id' => $opcr->id,
            'body'    => '<p>Noted.</p>',
        ])->assertCreated();

        $this->assertDatabaseMissing('notifications', ['user_id' => $president->id]);
    }
}
