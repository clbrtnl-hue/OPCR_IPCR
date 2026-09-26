<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/**
 * The OPCR is planned before it is delivered: only an administrator opens one,
 * QA approves the targets, and the administrator publishes. Until it is
 * published nobody else can see its targets or commit an IPCR line to them.
 */
class OpcrLifecycleTest extends PmsTestCase
{
    private function setUpOffice(): array
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        return [$unit, $year];
    }

    public function test_only_the_president_opens_the_opcr(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $this->actingAsUser($head);

        $this->postJson('/api/pcr-forms', [
            'type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(403);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/pcr-forms', [
            'type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(201);
    }

    public function test_the_college_gets_one_opcr_a_year(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/pcr-forms', [
            'type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $unit->id,
        ])->assertStatus(201);

        // A second one, even for a different office, is the same year's OPCR.
        $other = $this->makeUnit(['name' => 'BS Information Technology', 'code' => 'BSIT']);

        $this->postJson('/api/pcr-forms', [
            'type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $other->id,
        ])->assertStatus(409);
    }

    public function test_it_runs_draft_to_qa_approval_to_published(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $this->makeIndicator($opcr, 'core');

        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'qa_approval'])->assertStatus(200);

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'approved'])->assertStatus(200);

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'published'])->assertStatus(200);

        $this->assertSame('published', $opcr->fresh()->status);
    }

    public function test_publishing_notifies_accountable_people_to_write_their_ipcr(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'approved',
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);
        $other = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        app(PcrAssignmentService::class)->assignIndicator($target, $head, $president);

        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'published'])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'type'    => 'published',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $qa->id,
            'type'    => 'published',
            'form_id' => $opcr->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $other->id,
            'type'    => 'published',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $president->id,
            'type'    => 'published',
        ]);

        $ipcr = PcrForm::where('type', 'ipcr')->where('user_id', $head->id)->first();
        $this->assertNotNull($ipcr);
        $this->assertSame(
            "/forms/{$ipcr->id}",
            Notification::where('user_id', $head->id)->where('type', 'published')->value('link')
        );
    }

    public function test_qa_cannot_publish_and_a_draft_cannot_skip_qa(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'approved',
        ]);

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'published'])->assertStatus(403);

        // A draft cannot jump the QA step.
        $draft = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'draft',
        ]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$draft->id}/status", ['status' => 'published'])->assertStatus(409);
    }

    public function test_targets_are_hidden_until_the_opcr_is_published(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'qa_approval',
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $employee = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $ipcr     = $this->makeForm([
            'type' => 'ipcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => $employee->id,
        ]);

        $this->actingAsUser($employee);

        $this->getJson("/api/pcr-forms/{$ipcr->id}/opcr-targets")->assertStatus(200)->assertJsonCount(0);

        $output = $this->makeIndicator($ipcr, 'core')->output;
        $this->postJson('/api/pcr-indicators', [
            'output_id'         => $output->id,
            'description'       => 'Something.',
            'parent_indicator_id' => $target->id,
        ])->assertStatus(422);

        // Publishing opens it up.
        $opcr->update(['status' => 'published']);

        $this->getJson("/api/pcr-forms/{$ipcr->id}/opcr-targets")->assertStatus(200)->assertJsonCount(1);
    }

    public function test_the_president_can_unpublish_back_to_draft(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'draft'])->assertStatus(200);
        $this->assertSame('draft', $opcr->fresh()->status);
    }

    public function test_a_published_opcr_is_still_rated_later(): void
    {
        [$unit, $year] = $this->setUpOffice();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);

        $this->actingAsRole('admin', ['org_unit_id' => $unit->id]);

        Carbon::setTestNow('2026-12-15');

        try {
            $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'qa_rating'])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('qa_rating', $opcr->fresh()->status);
    }

    public function test_the_president_sends_a_published_opcr_for_rating_in_december(): void
    {
        [$unit, $year] = $this->setUpOffice();
        $year->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        Carbon::setTestNow('2026-06-15');

        try {
            $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'qa_rating'])
                ->assertStatus(409)
                ->assertJsonPath('message', 'The college OPCR is sent for rating in December, with the last period.');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('published', $opcr->fresh()->status);

        Carbon::setTestNow('2026-12-01');

        try {
            $this->getJson("/api/pcr-forms/{$opcr->id}")
                ->assertOk()
                ->assertJsonPath('rating_window_open', true);

            $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'qa_rating'])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('qa_rating', $opcr->fresh()->status);
    }

    public function test_an_opcr_line_covers_the_whole_year(): void
    {
        [$unit, $year] = $this->setUpOffice();
        $period = $this->makePeriod($year, 1);

        $opcr   = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'draft',
        ]);
        $output = $this->makeIndicator($opcr, 'core')->output;

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/pcr-indicators', [
            'output_id'        => $output->id,
            'description'      => 'Publish 25 articles.',
            'rating_period_id' => $period->id,
        ])->assertStatus(201);

        $this->assertNull(
            PcrIndicator::where('output_id', $output->id)
                ->where('description', 'like', '%25 articles%')
                ->value('rating_period_id')
        );
    }
}
