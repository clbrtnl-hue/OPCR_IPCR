<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

/**
 * Commitments are approved before anyone cascades or records tasks.
 * People named on the published OPCR are approved by the president.
 * Everyone below them is approved by the person who handed them the work.
 */
class CommitmentApprovalTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $college->id, 'name' => 'President Santos']);
        $vp        = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id, 'name' => 'VP Reyes']);
        $dean      = User::factory()->create(['role' => 'program_head', 'name' => 'Dean Cruz']);
        $chair     = User::factory()->create(['role' => 'program_head', 'name' => 'Chair Diaz']);
        $faculty   = User::factory()->create(['role' => 'employee', 'name' => 'Faculty Lee']);

        $office = $this->makeUnit([
            'name'         => 'College of Education',
            'code'         => 'COE',
            'parent_id'    => $college->id,
            'dean_user_id' => $dean->id,
            'vp_user_id'   => $vp->id,
            'head_user_id' => $chair->id,
        ]);

        $dean->update(['org_unit_id' => $office->id]);
        $chair->update(['org_unit_id' => $office->id]);
        $faculty->update(['org_unit_id' => $office->id]);

        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'org_unit_id'    => $college->id,
            'school_year_id' => $year->id,
            'status'         => 'published',
            'user_id'        => $president->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['rating_period_id' => $period->id]);

        return compact('college', 'office', 'year', 'period', 'president', 'vp', 'dean', 'chair', 'faculty', 'opcr', 'target');
    }

    public function test_opcr_accountable_people_are_approved_by_the_president_before_they_can_work(): void
    {
        ['period' => $period, 'president' => $president, 'vp' => $vp, 'dean' => $dean, 'target' => $target] = $this->college();

        $this->actingAsUser($president);
        app(PcrAssignmentService::class)->assignIndicator($target, $vp, $president, $period->id);

        $form = PcrForm::where('type', 'ipcr')->where('user_id', $vp->id)->firstOrFail();
        $line = $this->makeIndicator($form, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($vp);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $line->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => '<p>Not yet.</p>',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Commitments have to be approved before tasks can be recorded.');

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$dean->id]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Commitments have to be approved before work can be handed on.');

        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertOk();

        $form->refresh();
        $this->assertSame('commitment_review', $form->status);
        $this->assertSame($president->id, (int) $form->commitment_approver_id);
        $this->assertNull($form->commitment_approved_at);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $president->id,
            'form_id' => $form->id,
            'type'    => 'approval',
            'title'   => 'IPCR commitments from VP Reyes need your approval',
        ]);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitments_approved'])
            ->assertStatus(403);

        $this->actingAsUser($president);
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertOk()->json();
        $this->assertSame([$form->id], array_column($queue, 'id'));

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'note'   => 'Add a measure to the first commitment.',
        ])->assertOk();

        $this->assertSame('returned', $form->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vp->id,
            'type'    => 'returned',
            'title'   => 'Your commitments were returned for correction',
            'body'    => 'Add a measure to the first commitment.',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $dean->id,
            'type'    => 'returned',
            'form_id' => $form->id,
        ]);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$dean->id]])
            ->assertStatus(409);

        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame('commitment_review', $form->fresh()->status);

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitments_approved'])->assertOk();

        $form->refresh();
        $this->assertSame('commitments_approved', $form->status);
        $this->assertNotNull($form->commitment_approved_at);
        $this->assertSame($president->name, $form->commitment_approved_by_name);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vp->id,
            'type'    => 'approved',
            'title'   => 'Your commitments were approved',
        ]);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$dean->id]])
            ->assertStatus(201);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $line->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => '<p>Under way.</p>',
        ])->assertOk();

        $this->makeIndicator($form, 'support', ['rating_period_id' => $period->id]);

        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Finish the write-up before submitting.'));

        $this->assertSame('commitments_approved', $form->fresh()->status);
    }

    public function test_cascaded_commitments_are_approved_by_the_person_who_handed_them_down(): void
    {
        ['period' => $period, 'president' => $president, 'vp' => $vp, 'dean' => $dean, 'chair' => $chair, 'faculty' => $faculty, 'target' => $target] = $this->college();

        $this->actingAsUser($president);
        app(PcrAssignmentService::class)->assignIndicator($target, $vp, $president, $period->id);

        $vpForm = PcrForm::where('type', 'ipcr')->where('user_id', $vp->id)->firstOrFail();
        $vpLine = $this->makeIndicator($vpForm, 'core', ['rating_period_id' => $period->id]);
        $this->approveCommitments($vpForm, $president);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-indicators/{$vpLine->id}/assign", ['user_ids' => [$dean->id]])->assertStatus(201);

        $deanForm = PcrForm::where('type', 'ipcr')->where('user_id', $dean->id)->firstOrFail();
        $deanLine = $this->makeIndicator($deanForm, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$deanForm->id}/status", ['status' => 'commitment_review'])->assertOk();

        $deanForm->refresh();
        $this->assertSame('commitment_review', $deanForm->status);
        $this->assertSame($vp->id, (int) $deanForm->commitment_approver_id);

        $this->actingAsUser($president);
        $this->assertSame([], $this->getJson('/api/pcr-forms?queue=1')->json());

        $this->actingAsUser($vp);
        $ids = array_column($this->getJson('/api/pcr-forms?queue=1')->json(), 'id');
        $this->assertContains($deanForm->id, $ids);

        $this->postJson("/api/pcr-forms/{$deanForm->id}/status", ['status' => 'commitments_approved'])->assertOk();

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-indicators/{$deanLine->id}/assign", ['user_ids' => [$chair->id]])->assertStatus(201);

        $chairForm = PcrForm::where('type', 'ipcr')->where('user_id', $chair->id)->firstOrFail();
        $chairLine = $this->makeIndicator($chairForm, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($chair);
        $this->postJson("/api/pcr-forms/{$chairForm->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($dean->id, (int) $chairForm->fresh()->commitment_approver_id);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$chairForm->id}/status", ['status' => 'commitments_approved'])->assertOk();

        $this->actingAsUser($chair);
        $this->postJson("/api/pcr-indicators/{$chairLine->id}/assign", ['user_ids' => [$faculty->id]])->assertStatus(201);

        $facultyForm = PcrForm::where('type', 'ipcr')->where('user_id', $faculty->id)->firstOrFail();
        $this->makeIndicator($facultyForm, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($faculty);
        $this->postJson("/api/pcr-forms/{$facultyForm->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($chair->id, (int) $facultyForm->fresh()->commitment_approver_id);
    }

    public function test_a_person_who_was_not_assigned_is_approved_by_their_head(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'chair' => $chair, 'faculty' => $faculty] = $this->college();

        $form = $this->makeForm([
            'org_unit_id'      => $office->id,
            'school_year_id'   => $year->id,
            'user_id'          => $faculty->id,
            'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($form, 'support', ['rating_period_id' => $period->id]);

        $this->actingAsUser($faculty);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitment_review'])->assertOk();

        $this->assertSame($chair->id, (int) $form->fresh()->commitment_approver_id);
    }
}
