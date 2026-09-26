<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

/**
 * Commitments cascade. An office target is handed to a head, who breaks it into
 * sub-tasks and hands those down; the chain may go as deep as the work does, and
 * progress climbs back up as the average of whatever sits beneath each line.
 */
class PcrCascadeTest extends PmsTestCase
{
    private function office(): array
    {
        $this->makeOrganization();

        $unit   = $this->makeUnit();
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['rating_period_id' => $period->id]);

        $head    = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $other   = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        return compact('unit', 'year', 'period', 'opcr', 'target', 'head', 'faculty', 'other');
    }

    private function service(): PcrAssignmentService
    {
        return app(PcrAssignmentService::class);
    }

    public function test_assigning_opens_the_assignees_ipcr_without_copying_the_wording(): void
    {
        ['target' => $target, 'head' => $head] = $this->office();

        $admin = $this->actingAsRole('admin');
        $assignment = $this->service()->assignIndicator($target, $head, $admin);

        $form = PcrForm::where('type', 'ipcr')->where('user_id', $head->id)->first();

        $this->assertNotNull($form);
        $this->assertSame('draft', $form->status);
        $this->assertSame($head->id, (int) $assignment->user_id);
        $this->assertSame($target->id, (int) $assignment->indicator_id);
        $this->assertSame(0, PcrIndicator::where('parent_indicator_id', $target->id)->count());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'type'    => 'assignment',
            'link'    => "/forms/{$form->id}?target={$target->id}",
        ]);
    }

    public function test_it_cascades_three_deep(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty, 'other' => $other] = $this->office();

        $admin = $this->actingAsRole('admin');

        $this->service()->assignIndicator($target, $head, $admin);
        $headLine = $this->commitAgainst($target, $head);

        $this->service()->assignIndicator($headLine, $faculty, $head);
        $facultyLine = $this->commitAgainst($headLine, $faculty);

        $this->service()->assignIndicator($facultyLine, $other, $faculty);
        $deepest = $this->commitAgainst($facultyLine, $other);

        $this->assertSame($headLine->id, (int) $facultyLine->parent_indicator_id);
        $this->assertSame($facultyLine->id, (int) $deepest->parent_indicator_id);

        $cursor = $deepest;
        $depth  = 0;

        while ($cursor->parent) {
            $cursor = $cursor->parent;
            $depth++;
        }

        $this->assertSame(3, $depth);
        $this->assertSame($target->id, $cursor->id);
    }

    public function test_progress_is_the_average_of_the_children(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->office();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $head, $admin);
        $this->service()->assignIndicator($target, $faculty, $admin);

        $first  = $this->commitAgainst($target, $head);
        $second = $this->commitAgainst($target, $faculty);

        $this->documentLine($first);

        $this->assertSame(50, (int) $target->fresh()->progress_pct);
        $this->assertSame('ongoing', $target->fresh()->progress_status);

        $this->documentLine($second);

        $this->assertSame(100, (int) $target->fresh()->progress_pct);
        $this->assertSame('completed', $target->fresh()->progress_status);
    }

    public function test_progress_climbs_more_than_one_level(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->office();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $head, $admin);
        $headLine = $this->commitAgainst($target, $head);

        $this->service()->assignIndicator($headLine, $faculty, $head);
        $facultyLine = $this->commitAgainst($headLine, $faculty);

        $this->documentLine($facultyLine);

        $this->assertSame(100, (int) $headLine->fresh()->progress_pct);
        $this->assertSame(100, (int) $target->fresh()->progress_pct);
    }

    public function test_an_office_target_measures_the_work_beneath_its_heading(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty, 'period' => $period] = $this->office();

        $president = $this->actingAsRole('president');

        // The heading is handed over; the head writes their own commitments.
        $headOutput = $this->service()->assignOutput($target->output, $head, $president);

        $delegated = \App\Models\PcrIndicator::create([
            'output_id' => $headOutput->id, 'rating_period_id' => $period->id,
            'description' => 'Facilitate publication of 25 articles.',
        ]);
        $ownWork = \App\Models\PcrIndicator::create([
            'output_id' => $headOutput->id, 'rating_period_id' => $period->id,
            'description' => 'Conduct 1 research conference.',
        ]);

        // One of the two is passed to faculty, who finishes it.
        $this->service()->assignIndicator($delegated, $faculty, $head);
        $facultyLine = $this->commitAgainst($delegated, $faculty);

        $this->documentLine($facultyLine);

        // The head's delegated line follows its sub-task; their own work does not.
        $this->assertSame(100, (int) $delegated->fresh()->progress_pct);
        $this->assertSame(0, (int) $ownWork->fresh()->progress_pct);

        // And the office target averages what the head committed beneath it.
        $this->assertSame(50, (int) $target->fresh()->progress_pct);
        $this->assertSame('ongoing', $target->fresh()->progress_status);
    }

    public function test_a_line_with_sub_tasks_cannot_be_set_by_hand(): void
    {
        ['target' => $target, 'head' => $head] = $this->office();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $head, $admin);

        $this->postJson("/api/pcr-indicators/{$target->id}/progress", [
            'progress_status' => 'completed',
        ])->assertStatus(409);
    }

    public function test_an_assignment_lands_in_the_ipcr_for_its_own_period(): void
    {
        ['target' => $target, 'head' => $head, 'year' => $year] = $this->office();

        $period2 = $this->makePeriod($year, 2);
        $lateTarget = PcrIndicator::create([
            'output_id'        => $target->output_id,
            'rating_period_id' => $period2->id,
            'description'      => 'Publish 10 more articles before December.',
        ]);

        $admin = $this->actingAsRole('admin');

        $first  = $this->service()->assignIndicator($target, $head, $admin);
        $second = $this->service()->assignIndicator($lateTarget, $head, $admin);

        $this->assertNotSame($first->rating_period_id, $second->rating_period_id);
        $this->assertSame($target->rating_period_id, $first->rating_period_id);
        $this->assertSame($period2->id, (int) $second->rating_period_id);
        $this->assertSame(2, PcrForm::where('type', 'ipcr')->where('user_id', $head->id)->count());
    }

    public function test_a_heading_can_be_handed_out_once_per_period(): void
    {
        ['target' => $target, 'head' => $head, 'period' => $period, 'year' => $year] = $this->office();

        $period2 = $this->makePeriod($year, 2);

        $this->actingAsRole('admin');

        $this->postJson("/api/pcr-outputs/{$target->output_id}/assign", [
            'user_ids'         => [$head->id],
            'rating_period_id' => $period->id,
        ])->assertStatus(201)->assertJsonPath('assigned', 1);

        // The same period is already covered; the other half of the year is not.
        $this->postJson("/api/pcr-outputs/{$target->output_id}/assign", [
            'user_ids'         => [$head->id],
            'rating_period_id' => $period->id,
        ])->assertStatus(201)->assertJsonPath('assigned', 0);

        $this->postJson("/api/pcr-outputs/{$target->output_id}/assign", [
            'user_ids'         => [$head->id],
            'rating_period_id' => $period2->id,
        ])->assertStatus(201)->assertJsonPath('assigned', 1);
    }

    public function test_a_line_cannot_be_assigned_to_the_same_person_twice(): void
    {
        ['target' => $target, 'head' => $head, 'opcr' => $opcr] = $this->office();

        $this->actingAsRole('admin');

        $this->postJson("/api/pcr-indicators/{$target->id}/assign", ['user_ids' => [$head->id]])
            ->assertStatus(201)->assertJsonPath('assigned', 1);

        $this->postJson("/api/pcr-indicators/{$target->id}/assign", ['user_ids' => [$head->id]])
            ->assertStatus(201)->assertJsonPath('assigned', 0);

        $this->assertSame(1, \App\Models\PcrTargetAssignment::where('indicator_id', $target->id)->count());
    }

    public function test_withdrawing_is_refused_once_work_exists(): void
    {
        ['target' => $target, 'head' => $head] = $this->office();

        $admin = $this->actingAsRole('admin');
        $assignment = $this->service()->assignIndicator($target, $head, $admin);
        $this->commitAgainst($target, $head);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/pcr-assignments/{$assignment->id}")->assertStatus(409);

        $this->assertDatabaseHas('pcr_target_assignments', ['id' => $assignment->id]);
    }

    public function test_an_untouched_assignment_can_be_withdrawn(): void
    {
        ['target' => $target, 'head' => $head] = $this->office();

        $admin = $this->actingAsRole('admin');
        $assignment = $this->service()->assignIndicator($target, $head, $admin);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/pcr-assignments/{$assignment->id}")->assertSuccessful();

        $this->assertDatabaseMissing('pcr_target_assignments', ['id' => $assignment->id]);
        $this->assertSame(0, (int) $target->fresh()->progress_pct);
    }

    public function test_only_the_owner_may_hand_out_parts_of_a_line(): void
    {
        ['target' => $target, 'faculty' => $faculty, 'head' => $head] = $this->office();

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-indicators/{$target->id}/assign", ['user_ids' => [$head->id]])
            ->assertStatus(403);
    }

    public function test_the_picker_lists_everyone_but_system_accounts(): void
    {
        ['head' => $head] = $this->office();

        $this->actingAsUser($head);

        $people = $this->getJson('/api/assignable-users')->assertSuccessful()->json();
        $roles  = array_column($people, 'role');

        $this->assertNotContains('admin', $roles);
        $this->assertContains($head->id, array_column($people, 'id'));
    }

    public function test_a_head_sees_who_each_line_was_handed_to(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->office();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $head, $admin);
        $mine = $this->commitAgainst($target, $head);

        $this->actingAsUser($head);
        $this->service()->assignIndicator($mine, $faculty, $head);
        $this->commitAgainst($mine, $faculty);

        $form = $this->getJson("/api/pcr-forms/{$mine->output->form_id}")
            ->assertStatus(200)
            ->json();

        $line = collect($form['outputs'])
            ->flatMap(fn ($o) => $o['indicators'])
            ->firstWhere('id', $mine->id);

        $this->assertCount(1, $line['assignments']);
        $this->assertSame($faculty->name, $line['assignments'][0]['user']['name']);
        $this->assertCount(1, $line['children']);
        $this->assertSame($faculty->name, $line['children'][0]['output']['form']['owner']['name']);
    }

    public function test_a_head_can_only_assign_someone_on_the_team(): void
    {
        ['unit' => $unit, 'year' => $year, 'head' => $head, 'faculty' => $faculty] = $this->office();

        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG']);
        $outsider  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $elsewhere->id]);

        $form = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $line = $this->makeIndicator($form, 'core');

        $this->actingAsUser($head);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$outsider->id]])
            ->assertStatus(422);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$faculty->id]])
            ->assertStatus(201);

        $ids = array_column($this->getJson('/api/assignable-users')->json(), 'id');

        $this->assertContains($faculty->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_qa_can_assign_a_line_only_to_staff_in_the_office_they_head(): void
    {
        ['unit' => $unit, 'year' => $year, 'faculty' => $faculty] = $this->office();

        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $qa->id]);

        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG']);
        $outsider  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $elsewhere->id]);

        $form = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $qa->id,
        ]);
        $line = $this->makeIndicator($form, 'core');

        $this->actingAsUser($qa);

        $this->assertTrue($qa->fresh()->capabilities['assign_indicators']);
        $this->assertFalse($qa->fresh()->capabilities['assign_outputs']);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$outsider->id]])
            ->assertStatus(422);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$faculty->id]])
            ->assertStatus(201);

        $ids = array_column($this->getJson('/api/assignable-users')->json(), 'id');

        $this->assertContains($faculty->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_a_pending_assignment_is_on_the_ipcr_before_anyone_writes(): void
    {
        ['unit' => $unit, 'year' => $year, 'head' => $head, 'faculty' => $faculty] = $this->office();

        $form = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $line = $this->makeIndicator($form, 'core');

        $this->actingAsUser($head);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$faculty->id]])
            ->assertStatus(201);

        $shown = collect($this->getJson("/api/pcr-forms/{$form->id}")->assertOk()->json('outputs'))
            ->flatMap(fn ($output) => $output['indicators'])
            ->firstWhere('id', $line->id);

        $this->assertSame($faculty->name, $shown['assignments'][0]['user']['name']);
        $this->assertCount(0, $shown['children']);
    }

    public function test_a_faculty_member_sees_the_target_their_head_assigned(): void
    {
        ['unit' => $unit, 'year' => $year, 'period' => $period, 'target' => $officeTarget, 'head' => $head, 'faculty' => $faculty] = $this->office();

        $form = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
            'user_id' => $head->id, 'rating_period_id' => $period->id,
        ]);
        $line = $this->makeIndicator($form, 'core', [
            'description' => '<p>Raise the passing rate.</p>',
            'rating_period_id' => $period->id,
        ]);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$faculty->id]])
            ->assertStatus(201);

        $facultyForm = PcrForm::where('type', 'ipcr')->where('user_id', $faculty->id)->first();

        $this->actingAsUser($faculty);

        $targets = $this->getJson("/api/pcr-forms/{$facultyForm->id}")
            ->assertOk()
            ->json('assigned_targets');

        $this->assertCount(1, $targets);
        $this->assertSame($line->id, $targets[0]['id']);
        $this->assertSame($head->name, $targets[0]['assigned_by_name']);

        $picker = collect($this->getJson("/api/pcr-forms/{$facultyForm->id}/opcr-targets")->assertOk()->json());
        $this->assertTrue($picker->contains(fn ($row) => $row['id'] === $line->id && $row['assigned'] === true));
        $this->assertFalse($picker->contains(fn ($row) => $row['id'] === $officeTarget->id));

        $output = $this->makeIndicator($facultyForm, 'core')->output;

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $output->id,
            'description'         => 'Coach the review class.',
            'parent_indicator_id' => $officeTarget->id,
        ])->assertStatus(422);

        $this->postJson('/api/pcr-indicators', [
            'output_id'           => $output->id,
            'description'         => 'Coach the review class.',
            'parent_indicator_id' => $line->id,
        ])->assertSuccessful();
    }
}
