<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PcrIndicator;
use App\Models\PcrTargetAssignment;
use App\Models\PcrTargetReplacement;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

/**
 * A published OPCR target can be replaced in the middle of the year.
 * QA approves the new wording. Commitments already linked to the old
 * target close, and those people start a new one. Other lines stay.
 */
class TargetReplacementTest extends PmsTestCase
{
    public function test_replacing_a_published_target_restarts_only_the_linked_commitments(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'published',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $vp = User::factory()->create(['role' => 'vp', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $headLine = $this->commitAgainst($target, $head, ['rating_period_id' => $period->id]);
        $headForm = $headLine->output->form;
        $this->approveCommitments($headForm, $vp);

        $kept = PcrIndicator::create([
            'output_id'        => $headLine->output_id,
            'description'      => 'A commitment that stays.',
            'rating_period_id' => $period->id,
        ]);

        $staffLine = $this->commitAgainst($headLine, $staff, ['rating_period_id' => $period->id]);

        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/target-replacements', [
            'indicator_id' => $target->id,
            'description'  => 'Publish 40 articles from January to December 2026.',
        ])->assertStatus(201);

        $this->assertNull($target->fresh()->closed_at);
        $this->assertNull($headLine->fresh()->closed_at);

        $qa = $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $replacementId = PcrTargetReplacement::where('old_indicator_id', $target->id)->value('id');

        $this->postJson("/api/target-replacements/{$replacementId}/approve")->assertOk();

        $target->refresh();
        $headLine->refresh();
        $staffLine->refresh();
        $kept->refresh();

        $this->assertNotNull($target->closed_at);
        $this->assertSame('published', $opcr->fresh()->status);
        $this->assertNotNull($headLine->closed_at);
        $this->assertNotNull($staffLine->closed_at);
        $this->assertNull($kept->closed_at);
        $this->assertSame('A commitment that stays.', $kept->description);

        $newTarget = PcrIndicator::where('replaces_indicator_id', $target->id)->first();
        $headBlank = PcrIndicator::where('replaces_indicator_id', $headLine->id)->first();
        $staffBlank = PcrIndicator::where('replaces_indicator_id', $staffLine->id)->first();

        $this->assertSame('approved', $newTarget->replacement_status);
        $this->assertSame($newTarget->id, (int) $headBlank->parent_indicator_id);
        $this->assertTrue($headBlank->needs_commitment_approval);
        $this->assertSame($vp->id, (int) $headBlank->line_approver_id);
        $this->assertSame($headBlank->id, (int) $staffBlank->parent_indicator_id);
        $this->assertFalse($staffBlank->needs_commitment_approval);

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-indicators', [
            'id'          => $headBlank->id,
            'output_id'   => $headBlank->output_id,
            'description' => 'Forty articles from my office.',
        ])->assertOk();

        $this->postJson("/api/pcr-indicators/{$headBlank->id}/submit-commitment")->assertOk();

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-indicators/{$headBlank->id}/approve-commitment")->assertOk();
        $this->assertFalse($headBlank->fresh()->needs_commitment_approval);

        $headForm->update(['status' => 'qa_rating', 'rating_period_id' => $period->id]);
        $this->actingAsUser($qa);
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $headForm->id,
            'rating_period_id' => $period->id,
            'ratings'          => [[
                'indicator_id' => $headLine->id,
                'q' => 4, 'e' => 4, 't' => 4,
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'form_id' => $headForm->id,
        ]);
        $this->assertNotNull($president);
    }

    public function test_a_target_cannot_be_replaced_before_it_is_published(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'approved',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson('/api/target-replacements', [
            'indicator_id' => $target->id,
            'description'  => 'A different target.',
        ])->assertStatus(409);

        $this->assertNull($target->fresh()->closed_at);
    }

    public function test_sending_a_replacement_back_leaves_the_current_target(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'published',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $this->postJson('/api/target-replacements', [
            'indicator_id' => $target->id,
            'description'  => 'A different target.',
        ])->assertStatus(201);

        $replacementId = PcrTargetReplacement::where('old_indicator_id', $target->id)->value('id');

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/target-replacements/{$replacementId}/reject")->assertOk();

        $this->assertNull($target->fresh()->closed_at);
        $this->assertNull(PcrIndicator::where('replaces_indicator_id', $target->id)->first());
    }

    public function test_a_date_or_budget_change_stays_on_the_same_line(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'published',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core', [
            'allotted_budget' => 1000,
            'target_date'     => '2026-06-30',
        ]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $headLine = $this->commitAgainst($target, $head);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $this->postJson('/api/target-replacements', [
            'indicator_id'    => $target->id,
            'description'     => $target->description,
            'target_date'     => '2026-09-30',
            'allotted_budget' => 2500,
        ])->assertStatus(201);

        $replacementId = PcrTargetReplacement::where('old_indicator_id', $target->id)->value('id');

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/target-replacements/{$replacementId}/approve")->assertOk();

        $target->refresh();
        $this->assertNull($target->closed_at);
        $this->assertSame('2026-09-30', $target->target_date->toDateString());
        $this->assertSame(2500.0, (float) $target->allotted_budget);
        $this->assertSame($target->description, $target->fresh()->description);
        $this->assertNull($headLine->fresh()->closed_at);
        $this->assertNull(PcrIndicator::where('replaces_indicator_id', $target->id)->first());
    }

    public function test_several_lines_go_to_qa_together(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'published',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $wording = $this->makeIndicator($opcr, 'core', ['allotted_budget' => 100]);
        $money = $this->makeIndicator($opcr, 'core', [
            'description' => 'Keep this indicator.',
            'allotted_budget' => 500,
        ]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $this->postJson('/api/target-replacements/batch', [
            'lines' => [
                [
                    'indicator_id'    => $wording->id,
                    'description'     => 'A new indicator.',
                    'allotted_budget' => 100,
                ],
                [
                    'indicator_id'    => $money->id,
                    'description'     => 'Keep this indicator.',
                    'allotted_budget' => 900,
                ],
            ],
        ])->assertStatus(201);

        $this->assertSame(1, \App\Models\Notification::where('user_id', $qa->id)->where('type', 'approval')->count());

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$opcr->id}/target-replacements", ['approve' => true])->assertOk();

        $this->assertNotNull($wording->fresh()->closed_at);
        $money->refresh();
        $this->assertNull($money->closed_at);
        $this->assertSame(900.0, (float) $money->allotted_budget);
        $this->assertSame('Keep this indicator.', $money->description);
    }

    public function test_sending_changes_back_keeps_the_target_and_carries_the_note(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'published',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['description' => 'The current target.']);

        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $this->postJson('/api/target-replacements', [
            'indicator_id' => $target->id,
            'description'  => 'A different target.',
        ])->assertStatus(201);

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$opcr->id}/target-replacements", [
            'approve' => false,
        ])->assertStatus(422);

        $this->postJson("/api/pcr-forms/{$opcr->id}/target-replacements", [
            'approve' => false,
            'note'    => 'The date is too late.',
        ])->assertOk();

        $this->assertSame('The current target.', $target->fresh()->description);
        $this->assertNull($target->fresh()->closed_at);
        $this->assertSame(
            1,
            Notification::where('user_id', $president->id)->where('type', 'returned')->count()
        );
        $this->assertSame(
            'The date is too late.',
            Notification::where('user_id', $president->id)->where('type', 'returned')->value('body')
        );
    }

    public function test_approval_keeps_the_president_as_the_person_who_assigned_the_target(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $this->makePeriod($year);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'status' => 'published',
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core');
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        app(PcrAssignmentService::class)->assignIndicator($target, $head, $president, null, false);

        $this->postJson('/api/target-replacements', [
            'indicator_id' => $target->id,
            'description'  => 'A new indicator.',
            'user_ids'     => [$head->id],
        ])->assertStatus(201);

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$opcr->id}/target-replacements", [
            'approve' => true,
        ])->assertOk();

        $newTarget = PcrIndicator::where('replaces_indicator_id', $target->id)->first();
        $assignment = PcrTargetAssignment::where('indicator_id', $newTarget->id)
            ->where('user_id', $head->id)
            ->first();

        $this->assertSame($president->id, (int) $assignment->assigned_by);
        $this->assertSame($president->name, $assignment->assigned_by_name);
    }

    public function test_restarted_commitments_are_sent_and_approved_together(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $vp = User::factory()->create(['role' => 'vp', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'type' => 'ipcr',
            'status' => 'commitments_approved',
            'user_id' => $head->id,
            'org_unit_id' => $unit->id,
            'school_year_id' => $year->id,
            'rating_period_id' => $period->id,
            'commitment_approver_id' => $vp->id,
        ]);
        $first = $this->makeIndicator($form, 'core', [
            'description' => 'First new commitment.',
            'needs_commitment_approval' => true,
            'line_approver_id' => $vp->id,
            'rating_period_id' => $period->id,
        ]);
        $second = PcrIndicator::create([
            'output_id' => $first->output_id,
            'description' => 'Second new commitment.',
            'needs_commitment_approval' => true,
            'line_approver_id' => $vp->id,
            'rating_period_id' => $period->id,
        ]);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$form->id}/restart-commitments")->assertOk();

        $this->assertNotNull($first->fresh()->line_submitted_at);
        $this->assertNotNull($second->fresh()->line_submitted_at);
        $this->assertSame(
            1,
            Notification::where('user_id', $vp->id)->where('type', 'approval')->count()
        );

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/restart-commitments/decide", [
            'approve' => true,
        ])->assertOk();

        $this->assertNotNull($first->fresh()->line_approved_at);
        $this->assertNotNull($second->fresh()->line_approved_at);
        $this->assertSame(
            1,
            Notification::where('user_id', $head->id)->where('type', 'approved')->count()
        );
    }
}
