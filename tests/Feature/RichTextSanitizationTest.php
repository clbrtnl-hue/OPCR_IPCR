<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PcrAccomplishment;
use App\Models\PcrIndicator;
use App\Models\PcrStatusLog;
use App\Models\User;
use App\Services\PcrAssignmentService;
use App\Support\Html;
use Tests\PmsTestCase;

/**
 * Commitments, accomplishments and remarks are written by one person, read by
 * another, and printed onto the CSC-facing form. Everything is cleaned at the
 * boundary, so the database never holds anything that could run in a reader's
 * browser — and the formatting people actually use survives.
 */
class RichTextSanitizationTest extends PmsTestCase
{
    private function scenario(): array
    {
        $this->makeOrganization();

        $unit   = $this->makeUnit();
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $form     = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);
        $indicator = $this->makeIndicator($form, 'core', ['rating_period_id' => $period->id]);

        return compact('unit', 'year', 'period', 'employee', 'form', 'indicator');
    }

    public function test_the_cleaner_keeps_formatting_and_drops_everything_else(): void
    {
        $this->assertSame('<p>Hello</p>', Html::clean('<script>alert(1)</script>Hello'));
        $this->assertSame('<p>A <strong>bold</strong> claim</p>', Html::clean('<p>A <strong>bold</strong> claim</p>'));
        $this->assertSame('<ul><li>one</li></ul>', Html::clean('<ul><li>one</li></ul>'));

        // An image with an event handler has nothing left worth keeping.
        $this->assertNull(Html::clean('<img src=x onerror=alert(1)>'));

        // A mention is a link to a person; a javascript: href is not.
        $this->assertStringContainsString('href="/people/7"', Html::clean('<a href="/people/7">@Someone</a>'));
        $this->assertStringNotContainsString('javascript', (string) Html::clean('<a href="javascript:alert(1)">x</a>'));

        // Text stored before any of this still reads back.
        $this->assertSame('<p>plain sentence</p>', Html::clean('plain sentence'));
        $this->assertNull(Html::clean('<p></p>'));
    }

    public function test_an_accomplishment_is_cleaned_on_the_way_in(): void
    {
        ['employee' => $employee, 'indicator' => $indicator, 'period' => $period] = $this->scenario();

        $this->actingAsUser($employee);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => '<p>Delivered <strong>25</strong></p><script>alert(1)</script>',
        ])->assertSuccessful();

        $stored = PcrAccomplishment::first()->actual_accomplishment;

        $this->assertStringContainsString('<strong>25</strong>', $stored);
        $this->assertStringNotContainsString('script', $stored);
    }

    public function test_a_commitment_is_cleaned_on_the_way_in(): void
    {
        ['employee' => $employee, 'indicator' => $indicator, 'period' => $period] = $this->scenario();

        $this->actingAsUser($employee);

        $response = $this->postJson('/api/pcr-indicators', [
            'output_id'        => $indicator->output_id,
            'rating_period_id' => $period->id,
            'description'      => '<p>Publish <em>25</em> articles</p><script>steal()</script>',
        ])->assertStatus(201);

        $stored = PcrIndicator::find($response->json('indicator.id'))->description;

        $this->assertStringContainsString('<em>25</em>', $stored);
        $this->assertStringNotContainsString('script', $stored);
    }

    public function test_a_return_note_is_cleaned_and_logged_as_words(): void
    {
        ['employee' => $employee, 'form' => $form, 'unit' => $unit] = $this->scenario();

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);

        $this->actingAsUser($employee);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertSuccessful();

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'note'   => '<p>Attach the <strong>letters</strong></p><script>alert(1)</script>',
        ])->assertSuccessful();

        // The timeline and notifications are plain-text surfaces; markup there
        // would read as gibberish.
        $log = PcrStatusLog::where('form_id', $form->id)->where('to_status', 'returned')->first();

        $this->assertSame('Attach the letters', $log->note);
        $this->assertStringNotContainsString('<', $log->note);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'body'    => 'Attach the letters',
        ]);
    }

    public function test_the_audit_trail_carries_words_rather_than_markup(): void
    {
        ['unit' => $unit, 'form' => $form, 'indicator' => $indicator] = $this->scenario();

        $indicator->update(['description' => '<p>Publish <strong>25</strong> articles.</p>']);

        $assignee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $actor    = $this->actingAsRole('admin');

        app(PcrAssignmentService::class)->assignIndicator($indicator, $assignee, $actor);

        $entry = ActivityLog::where('subject_type', 'PcrIndicator')->where('action', 'assign')->first();

        $this->assertStringNotContainsString('<', $entry->description);
        $this->assertStringContainsString('Publish 25 articles.', $entry->description);
    }

    public function test_audit_rows_written_before_the_change_still_read_as_words(): void
    {
        $this->scenario();

        ActivityLog::create([
            'subject_type' => 'PcrIndicator',
            'subject_id'   => 1,
            'action'       => 'assign',
            'description'  => '<p>Assigned to Bernadeth: <strong>file the inventory</strong></p>',
        ]);

        $this->actingAsRole('admin');

        $response = $this->getJson('/api/audit-logs')->assertOk();

        $descriptions = collect($response->json('logs'))->pluck('description')->filter();

        $this->assertTrue($descriptions->contains('Assigned to Bernadeth: file the inventory'));
        $this->assertTrue($descriptions->every(fn ($text) => ! str_contains($text, '<')));
    }

    public function test_a_rating_remark_is_cleaned_on_the_way_in(): void
    {
        ['form' => $form, 'indicator' => $indicator, 'period' => $period] = $this->scenario();

        $form->update(['status' => 'qa_rating']);
        $this->actingAsRole('qa');

        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [[
                'indicator_id' => $indicator->id,
                'q' => 5, 'e' => 5, 't' => 5,
                'remarks' => '<p>Well <strong>done</strong></p><script>alert(1)</script>',
            ]],
        ])->assertSuccessful();

        $stored = \App\Models\PcrRating::first()->remarks;

        $this->assertStringContainsString('<strong>done</strong>', $stored);
        $this->assertStringNotContainsString('script', $stored);
    }
}
