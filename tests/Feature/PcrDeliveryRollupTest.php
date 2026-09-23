<?php

namespace Tests\Feature;

use App\Models\PcrAttachment;
use App\Models\PcrComment;
use App\Models\PcrIndicator;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

/**
 * An office target is evidenced one level down, on the IPCR of whoever it was
 * cascaded to. The roll-up carries that evidence back up to the OPCR line, and
 * the permission to read it comes from the OPCR — not from each child's IPCR.
 */
class PcrDeliveryRollupTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);
        $later  = $this->makePeriod($year, 2);

        $head = User::factory()->create(['role' => 'program_head']);

        $office = $this->makeUnit(['name' => 'Office of Instruction', 'code' => 'INS']);
        $head->update(['org_unit_id' => $office->id]);
        $office->update(['head_user_id' => $head->id]);

        // The assignee sits in a different office, so the head cannot open
        // their IPCR directly — the whole point of the authorisation test.
        $elsewhere = $this->makeUnit(['name' => 'Office of the Registrar', 'code' => 'REG']);
        $faculty   = User::factory()->create(['role' => 'employee', 'org_unit_id' => $elsewhere->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $office->id,
            'school_year_id' => $year->id, 'status' => 'published',
            'user_id' => null,
        ]);
        $target = $this->makeIndicator($opcr, 'core');

        return compact('year', 'period', 'later', 'head', 'faculty', 'office', 'elsewhere', 'opcr', 'target');
    }

    private function service(): PcrAssignmentService
    {
        return app(PcrAssignmentService::class);
    }

    public function test_it_returns_what_each_assignee_recorded_against_the_office_target(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);
        $child = $this->commitAgainst($target, $faculty);

        $accomplishment = $child->accomplishments()->create([
            'rating_period_id'      => $child->rating_period_id,
            'actual_accomplishment' => '<p>Enrolment rose 6.2%.</p>',
            'remarks'               => '<p>Registrar report attached.</p>',
        ]);

        PcrAttachment::create([
            'accomplishment_id' => $accomplishment->id,
            'file_path'         => 'pcr-abc123.pdf',
            'original_name'     => 'registrar-report.pdf',
            'mime'              => 'application/pdf',
            'file_size'         => 2048,
            'uploaded_by'       => $faculty->id,
            'uploaded_by_name'  => $faculty->name,
        ]);

        $this->actingAsUser($head);

        $this->getJson("/api/pcr-indicators/{$target->id}/rollup")
            ->assertOk()
            ->assertJsonCount(1, 'delivered')
            ->assertJsonPath('delivered.0.owner.name', $faculty->name)
            ->assertJsonPath('delivered.0.accomplishment.actual_accomplishment', '<p>Enrolment rose 6.2%.</p>')
            ->assertJsonPath('delivered.0.accomplishment.remarks', '<p>Registrar report attached.</p>')
            ->assertJsonCount(1, 'delivered.0.accomplishment.attachments')
            ->assertJsonPath('delivered.0.accomplishment.attachments.0.original_name', 'registrar-report.pdf');
    }

    public function test_permission_comes_from_the_office_target_not_the_assignees_ipcr(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);
        $child = $this->commitAgainst($target, $faculty);

        $this->actingAsUser($head);

        $this->getJson("/api/pcr-forms/{$child->output->form_id}")->assertStatus(403);

        $this->getJson("/api/pcr-indicators/{$target->id}/rollup")
            ->assertOk()
            ->assertJsonPath('delivered.0.owner.name', $faculty->name);
    }

    public function test_somebody_who_cannot_see_the_office_target_is_refused(): void
    {
        ['target' => $target, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);

        $this->actingAsUser($faculty);

        $this->getJson("/api/pcr-indicators/{$target->id}/rollup")->assertStatus(403);
    }

    public function test_it_returns_only_the_remarks_on_that_line(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);
        $child = $this->commitAgainst($target, $faculty);

        $childForm = $child->output->form;
        $other     = $this->makeIndicator($childForm, 'core');

        PcrComment::create([
            'form_id' => $childForm->id, 'indicator_id' => $child->id,
            'stage' => 'employee', 'body' => '<p>On this line.</p>',
            'author_id' => $faculty->id, 'author_name' => $faculty->name, 'author_role' => 'employee',
        ]);
        PcrComment::create([
            'form_id' => $childForm->id, 'indicator_id' => $other->id,
            'stage' => 'employee', 'body' => '<p>On another line.</p>',
            'author_id' => $faculty->id, 'author_name' => $faculty->name, 'author_role' => 'employee',
        ]);
        PcrComment::create([
            'form_id' => $childForm->id, 'indicator_id' => null,
            'stage' => 'head', 'body' => '<p>About the whole form.</p>',
            'author_id' => $head->id, 'author_name' => $head->name, 'author_role' => 'program_head',
        ]);

        $this->actingAsUser($head);

        $response = $this->getJson("/api/pcr-indicators/{$target->id}/rollup")->assertOk();

        $bodies = array_column($response->json('delivered.0.comments'), 'body');

        $this->assertSame(['<p>On this line.</p>'], $bodies);
    }

    public function test_it_does_not_reach_past_the_first_level(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin  = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $head, $admin);
        $middle = $this->commitAgainst($target, $head);

        $this->actingAsUser($head);
        $this->service()->assignIndicator($middle, $faculty, $head);
        $grandchild = $this->commitAgainst($middle, $faculty);

        $grandchild->accomplishments()->create([
            'rating_period_id'      => $grandchild->rating_period_id,
            'actual_accomplishment' => '<p>Two hops down.</p>',
        ]);

        $this->actingAsUser($head);

        $response = $this->getJson("/api/pcr-indicators/{$target->id}/rollup")
            ->assertOk()
            ->assertJsonCount(1, 'delivered')
            ->assertJsonPath('delivered.0.children_count', 1);

        $this->assertStringNotContainsString('Two hops down.', $response->getContent());
    }

    public function test_it_returns_work_recorded_in_the_other_half_of_the_year(): void
    {
        [
            'target'  => $target, 'head' => $head, 'faculty' => $faculty,
            'period'  => $period, 'later' => $later,
        ] = $this->college();

        $admin = $this->actingAsRole('admin');
        $other = User::factory()->create(['role' => 'employee', 'org_unit_id' => $faculty->org_unit_id]);

        $this->service()->assignIndicator($target, $faculty, $admin, $period->id);
        $this->service()->assignIndicator($target, $other, $admin, $later->id);

        $this->actingAsUser($head);

        // Asking for the first period must not hide the person working the second.
        $response = $this->getJson("/api/pcr-indicators/{$target->id}/rollup?rating_period_id={$period->id}")
            ->assertOk()
            ->assertJsonCount(2, 'delivered');

        $periods = array_column($response->json('delivered'), 'rating_period_id');

        $this->assertContains($period->id, $periods);
        $this->assertContains($later->id, $periods);
        $this->assertSame(
            [true, false],
            collect($response->json('delivered'))
                ->sortBy('rating_period_id')
                ->pluck('is_current_period')
                ->values()
                ->all()
        );
    }

    public function test_a_line_nobody_was_given_answers_with_an_empty_roll_up(): void
    {
        ['target' => $target, 'head' => $head] = $this->college();

        $this->actingAsUser($head);

        $this->getJson("/api/pcr-indicators/{$target->id}/rollup")
            ->assertOk()
            ->assertJsonCount(0, 'delivered');
    }

    public function test_the_sheet_is_told_how_much_evidence_sits_under_each_line(): void
    {
        ['target' => $target, 'opcr' => $opcr, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);
        $child = $this->commitAgainst($target, $faculty);

        $accomplishment = $child->accomplishments()->create([
            'rating_period_id'      => $child->rating_period_id,
            'actual_accomplishment' => '<p>Done.</p>',
        ]);

        PcrAttachment::create([
            'accomplishment_id' => $accomplishment->id,
            'file_path'         => 'pcr-xyz.png',
            'original_name'     => 'proof.png',
            'mime'              => 'image/png',
            'file_size'         => 512,
        ]);

        $this->actingAsUser($head);

        $line = collect($this->getJson("/api/pcr-forms/{$opcr->id}")->assertOk()->json('outputs'))
            ->flatMap(fn ($output) => $output['indicators'])
            ->firstWhere('id', $target->id);

        $this->assertSame(1, $line['children'][0]['recorded_count']);
        $this->assertSame(1, $line['children'][0]['attachments_count']);
        $this->assertSame($child->rating_period_id, $line['children'][0]['rating_period_id']);
    }

    public function test_an_empty_accomplishment_does_not_count_as_recorded(): void
    {
        ['target' => $target, 'opcr' => $opcr, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);
        $child = $this->commitAgainst($target, $faculty);

        $child->accomplishments()->create([
            'rating_period_id'      => $child->rating_period_id,
            'actual_accomplishment' => null,
        ]);

        $this->actingAsUser($head);

        $line = collect($this->getJson("/api/pcr-forms/{$opcr->id}")->assertOk()->json('outputs'))
            ->flatMap(fn ($output) => $output['indicators'])
            ->firstWhere('id', $target->id);

        $this->assertSame(0, $line['children'][0]['recorded_count']);

        $this->getJson("/api/pcr-indicators/{$target->id}/rollup")
            ->assertOk()
            ->assertJsonPath('delivered.0.accomplishment.actual_accomplishment', null);
    }

    public function test_the_roll_up_stays_read_only(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);
        $child = $this->commitAgainst($target, $faculty);

        $this->actingAsUser($head);

        $this->postJson('/api/pcr-comments', [
            'form_id'      => $child->output->form_id,
            'indicator_id' => $child->id,
            'body'         => '<p>Let me answer here.</p>',
        ])->assertStatus(403);

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $child->id,
            'rating_period_id'      => $child->rating_period_id,
            'actual_accomplishment' => '<p>Written by the head.</p>',
        ])->assertStatus(403);
    }

    public function test_a_child_with_nothing_recorded_still_names_the_person(): void
    {
        ['target' => $target, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $admin = $this->actingAsRole('admin');
        $this->service()->assignIndicator($target, $faculty, $admin);

        $this->actingAsUser($head);

        $this->getJson("/api/pcr-indicators/{$target->id}/rollup")
            ->assertOk()
            ->assertJsonPath('delivered.0.owner.name', $faculty->name)
            ->assertJsonPath('delivered.0.accomplishment', null)
            ->assertJsonCount(0, 'delivered.0.comments');
    }

    public function test_it_refuses_an_indicator_that_does_not_exist(): void
    {
        $this->college();
        $this->actingAsRole('admin');

        $this->getJson('/api/pcr-indicators/' . (PcrIndicator::max('id') + 99) . '/rollup')
            ->assertStatus(404);
    }
}
