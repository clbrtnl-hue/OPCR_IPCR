<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

/**
 * Commitments are approved before anyone cascades or records tasks.
 * Nobody sends until the college OPCR is published. People named on that
 * OPCR (and QA) are approved by the president; everyone else by the person
 * who handed them the work or their unit head.
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

        // Accountable people can be named while drafting, like an OPCR.
        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$dean->id]])
            ->assertStatus(201);

        $deanForm = PcrForm::where('type', 'ipcr')->where('user_id', $dean->id)->firstOrFail();
        $this->makeIndicator($deanForm, 'core', ['rating_period_id' => $period->id]);

        // The assignee may write, but cannot send until this form is approved.
        $this->actingAsUser($dean);
        $this->assertSame(
            "Waiting for VP Reyes's commitments to be approved before these can be sent.",
            $this->getJson("/api/pcr-forms/{$deanForm->id}")->assertOk()->json('commitment_wait')
        );
        $this->postJson("/api/pcr-forms/{$deanForm->id}/status", ['status' => 'commitment_review'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "Waiting for VP Reyes's commitments to be approved before these can be sent."
            );

        $this->actingAsUser($vp);
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
            'link'    => "/review-queue?form={$form->id}",
        ]);

        // While commitments sit with the approver, naming people is paused.
        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$dean->id]])
            ->assertStatus(409);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitments_approved'])
            ->assertStatus(403);

        $this->actingAsUser($president);
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertOk()->json();
        $this->assertSame([$form->id], array_column($queue, 'id'));

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [[
                'indicator_id' => $line->id,
                'field'        => 'description',
                'note'         => 'Add a measure to the first commitment.',
            ]],
        ])->assertOk();

        $this->assertSame('returned', $form->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vp->id,
            'type'    => 'returned',
            'title'   => 'Your commitments were returned for correction',
            'body'    => 'Add a measure to the first commitment.',
            'link'    => "/forms/{$form->id}?line={$line->id}&fix=1",
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $dean->id,
            'type'    => 'returned',
            'form_id' => $form->id,
        ]);

        $this->actingAsUser($vp);
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

    public function test_nobody_sends_commitments_until_the_opcr_is_published(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'president' => $president, 'vp' => $vp, 'dean' => $dean, 'chair' => $chair, 'faculty' => $faculty, 'opcr' => $opcr, 'target' => $target] = $this->college();

        $opcr->update(['status' => 'draft']);

        $this->actingAsUser($president);
        app(PcrAssignmentService::class)->assignIndicator($target, $dean, $president, $period->id);

        $named = PcrForm::where('type', 'ipcr')->where('user_id', $dean->id)->firstOrFail();
        $this->makeIndicator($named, 'core', ['rating_period_id' => $period->id]);

        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $office->id, 'name' => 'QA Director']);
        $qaForm = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $qa->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($qaForm, 'core', ['rating_period_id' => $period->id]);

        $unrelated = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($unrelated, 'support', ['rating_period_id' => $period->id]);

        $wait = 'The college OPCR is not published yet, so these commitments cannot be sent.';

        foreach ([[$dean, $named, null], [$qa, $qaForm, $president->id], [$faculty, $unrelated, $chair->id]] as [$actor, $form, $approverId]) {
            $this->actingAsUser($actor);

            $sheet = $this->getJson("/api/pcr-forms/{$form->id}")->assertOk();
            $sheet->assertJsonPath('commitment_wait', $wait);
            if ($approverId === null) {
                $sheet->assertJsonPath('commitment_approver', null);
            } else {
                $sheet->assertJsonPath('commitment_approver.id', $approverId);
            }

            $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitment_review'])
                ->assertStatus(422)
                ->assertJsonPath('message', $wait);

            $this->assertSame('draft', $form->fresh()->status);
            $this->assertNull($form->fresh()->commitment_approver_id);
        }

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $vp->id,
            'form_id' => $named->id,
            'type'    => 'approval',
        ]);

        $opcr->update(['status' => 'published']);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$named->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($president->id, (int) $named->fresh()->commitment_approver_id);

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$qaForm->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($president->id, (int) $qaForm->fresh()->commitment_approver_id);

        $this->actingAsUser($faculty);
        $this->postJson("/api/pcr-forms/{$unrelated->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($chair->id, (int) $unrelated->fresh()->commitment_approver_id);
    }

    public function test_a_head_under_two_vps_has_commitments_approved_by_the_president(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);
        $sasVp     = User::factory()->create(['role' => 'vp', 'name' => 'VP Baculio']);
        $afsVp     = User::factory()->create(['role' => 'vp', 'name' => 'VP Vacalares']);
        $president = User::factory()->create(['role' => 'president', 'name' => 'President Neilson']);
        $head      = User::factory()->create(['role' => 'program_head', 'name' => 'Head Cariaga']);

        $sas = $this->makeUnit([
            'name' => 'Student Affairs', 'code' => 'SAS', 'parent_id' => $college->id, 'vp_user_id' => $sasVp->id,
        ]);
        $afs = $this->makeUnit([
            'name' => 'Admin and Finance', 'code' => 'AFS', 'parent_id' => $college->id, 'vp_user_id' => $afsVp->id,
        ]);
        $support = $this->makeUnit([
            'name' => 'Information and Support', 'code' => 'ISS', 'parent_id' => $sas->id, 'head_user_id' => $head->id,
        ]);
        $maintenance = $this->makeUnit([
            'name' => 'Information and Maintenance', 'code' => 'IMC', 'parent_id' => $afs->id, 'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $support->id]);

        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $maintenance->id]);

        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id, 'school_year_id' => $year->id,
            'status' => 'published', 'user_id' => $president->id,
        ]);

        $own = $this->makeForm([
            'org_unit_id' => $support->id, 'school_year_id' => $year->id, 'user_id' => $head->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($own, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($president->id, (int) $own->fresh()->commitment_approver_id);

        $this->actingAsUser($sasVp);
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitments_approved'])->assertStatus(403);

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitments_approved'])->assertOk();
        $this->assertSame('commitments_approved', $own->fresh()->status);

        $staffForm = $this->makeForm([
            'org_unit_id' => $maintenance->id, 'school_year_id' => $year->id, 'user_id' => $staff->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($staffForm, 'support', ['rating_period_id' => $period->id]);

        $this->actingAsUser($staff);
        $this->postJson("/api/pcr-forms/{$staffForm->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($head->id, (int) $staffForm->fresh()->commitment_approver_id);
    }

    public function test_the_presidents_secretary_has_commitments_approved_by_the_president(): void
    {
        $this->makeOrganization();

        $college   = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year      = $this->makeSchoolYear();
        $period    = $this->makePeriod($year);
        $president = User::factory()->create(['role' => 'president', 'name' => 'President Neilson']);
        $college->update(['head_user_id' => $president->id]);

        $secretary = User::factory()->create([
            'role'           => 'employee',
            'name'           => 'Fabian Singuil',
            'org_unit_id'    => $college->id,
            'position_title' => 'Executive Secretary',
        ]);

        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id, 'school_year_id' => $year->id,
            'status' => 'published', 'user_id' => $president->id,
        ]);

        $form = $this->makeForm([
            'org_unit_id'      => $college->id,
            'school_year_id'   => $year->id,
            'user_id'          => $secretary->id,
            'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($form, 'support', ['rating_period_id' => $period->id]);

        $this->actingAsUser($secretary);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($president->id, (int) $form->fresh()->commitment_approver_id);

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitments_approved'])->assertOk();
        $this->assertSame('commitments_approved', $form->fresh()->status);
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

    public function test_qa_commitments_are_approved_by_the_president(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'president' => $president] = $this->college();

        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $office->id, 'name' => 'QA Director']);
        $form = $this->makeForm([
            'org_unit_id'      => $office->id,
            'school_year_id'   => $year->id,
            'user_id'          => $qa->id,
            'rating_period_id' => $period->id,
        ]);
        $line = $this->makeIndicator($form, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($qa);
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('commitment_approver.id', $president->id)
            ->assertJsonPath('commitment_wait', null);

        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitment_review'])->assertOk();

        $form->refresh();
        $this->assertSame('commitment_review', $form->status);
        $this->assertSame($president->id, (int) $form->commitment_approver_id);

        $this->actingAsUser($president);
        $ids = array_column($this->getJson('/api/pcr-forms?queue=1')->assertOk()->json(), 'id');
        $this->assertContains($form->id, $ids);

        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitments_approved'])->assertOk();

        $this->documentLine($line);
        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertOk();

        $fresh = $form->fresh();
        $this->assertSame('qa_rating', $fresh->status);
        $this->assertNull($fresh->vp_reviewer_id);

        $this->actingAsUser($qa);
        $this->assertNotContains($form->id, array_column($this->getJson('/api/pcr-forms?queue=1')->json(), 'id'));

        $this->actingAsUser($president);
        $this->assertContains($form->id, array_column($this->getJson('/api/pcr-forms?queue=1')->json(), 'id'));
    }

    public function test_a_head_with_no_vp_has_commitments_approved_by_the_next_reviewer(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);
        $president = User::factory()->create(['role' => 'president', 'name' => 'President Santos']);
        $qa        = User::factory()->create(['role' => 'qa', 'name' => 'QA Director']);
        $head      = User::factory()->create(['role' => 'program_head', 'name' => 'Head Diaz']);
        $staff     = User::factory()->create(['role' => 'employee', 'name' => 'Staff Lee']);

        $office = $this->makeUnit([
            'name'         => 'Research Office',
            'code'         => 'RES',
            'parent_id'    => $college->id,
            'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);
        $staff->update(['org_unit_id' => $office->id]);

        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id, 'school_year_id' => $year->id,
            'status' => 'published', 'user_id' => $president->id,
        ]);

        $own = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id,
            'user_id' => $head->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($own, 'support', ['rating_period_id' => $period->id]);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($qa->id, (int) $own->fresh()->commitment_approver_id);

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitments_approved'])->assertStatus(403);

        $this->actingAsUser($qa);
        $this->assertContains(
            $own->id,
            array_column($this->getJson('/api/pcr-forms?queue=1')->assertOk()->json(), 'id')
        );
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitments_approved'])->assertOk();
        $this->assertSame('commitments_approved', $own->fresh()->status);

        $staffForm = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id,
            'user_id' => $staff->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($staffForm, 'support', ['rating_period_id' => $period->id]);

        $this->actingAsUser($staff);
        $this->postJson("/api/pcr-forms/{$staffForm->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($head->id, (int) $staffForm->fresh()->commitment_approver_id);
    }

    public function test_a_head_with_an_empty_vp_slot_still_goes_to_the_parent_vp(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC2', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);
        $president = User::factory()->create(['role' => 'president', 'name' => 'President Santos']);
        $vp        = User::factory()->create(['role' => 'vp', 'name' => 'VP Reyes']);
        User::factory()->create(['role' => 'qa', 'name' => 'QA Director']);
        $head = User::factory()->create(['role' => 'program_head', 'name' => 'Head Diaz']);

        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'parent_id' => $college->id, 'vp_user_id' => $vp->id,
        ]);
        $office = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'parent_id' => $affairs->id, 'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);

        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $college->id, 'school_year_id' => $year->id,
            'status' => 'published', 'user_id' => $president->id,
        ]);

        $own = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id,
            'user_id' => $head->id, 'rating_period_id' => $period->id,
        ]);
        $this->makeIndicator($own, 'support', ['rating_period_id' => $period->id]);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$own->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($vp->id, (int) $own->fresh()->commitment_approver_id);
    }

    public function test_a_first_period_opcr_assignment_still_sends_the_second_period_to_the_president(): void
    {
        ['year' => $year, 'period' => $period, 'office' => $office, 'president' => $president, 'dean' => $dean, 'target' => $target] = $this->college();

        $later = $this->makePeriod($year, 2);

        $this->actingAsUser($president);
        app(PcrAssignmentService::class)->assignIndicator($target, $dean, $president, $period->id);

        $second = $this->makeForm([
            'org_unit_id'      => $office->id,
            'school_year_id'   => $year->id,
            'user_id'          => $dean->id,
            'rating_period_id' => $later->id,
        ]);
        $this->makeIndicator($second, 'core', ['rating_period_id' => $later->id]);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$second->id}/status", ['status' => 'commitment_review'])->assertOk();

        $second->refresh();
        $this->assertSame('commitment_review', $second->status);
        $this->assertSame($president->id, (int) $second->commitment_approver_id);
    }
}
