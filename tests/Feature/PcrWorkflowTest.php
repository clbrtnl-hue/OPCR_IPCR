<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\PcrStatusLog;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\PmsTestCase;

class PcrWorkflowTest extends PmsTestCase
{
    private function scenario(): array
    {
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $unit   = $this->makeUnit();

        $head     = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $vp       = User::factory()->create(['role' => 'vp']);
        $qa       = User::factory()->create(['role' => 'qa']);
        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $unit->update(['head_user_id' => $head->id, 'vp_user_id' => $vp->id]);

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $employee->id,
        ]);

        $this->makeIndicator($form);

        return compact('year', 'period', 'unit', 'head', 'vp', 'qa', 'employee', 'form');
    }

    private function move(User $actor, PcrForm $form, string $status, ?string $note = null)
    {
        Passport::actingAs($actor, [], 'api');

        return $this->postJson("/api/pcr-forms/{$form->id}/status", array_filter([
            'status' => $status,
            'note'   => $note,
        ]));
    }

    public function test_form_travels_the_full_chain_to_qa(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head, 'vp' => $vp] = $this->scenario();

        $this->move($employee, $form, 'head_review')->assertOk();
        $this->assertSame('head_review', $form->fresh()->status);
        $this->assertNotNull($form->fresh()->submitted_at);

        $this->move($head, $form, 'vp_review')->assertOk();
        $this->assertSame($head->name, $form->fresh()->reviewed_by_name);

        $this->move($vp, $form, 'qa_rating')->assertOk();
        $this->assertSame('qa_rating', $form->fresh()->status);
        $this->assertSame($vp->name, $form->fresh()->vp_reviewed_by_name);
    }

    public function test_employee_cannot_skip_the_head_and_send_straight_to_qa(): void
    {
        ['form' => $form, 'employee' => $employee] = $this->scenario();

        // The destination is computed from the hierarchy rather than taken from
        // the request, so asking for QA lands on the program head anyway.
        $this->move($employee, $form, 'qa_rating')->assertSuccessful();

        $this->assertSame('head_review', $form->fresh()->status);
    }

    public function test_vp_cannot_endorse_a_form_still_waiting_for_the_head(): void
    {
        ['form' => $form, 'employee' => $employee, 'vp' => $vp] = $this->scenario();

        $this->move($employee, $form, 'head_review')->assertOk();

        $this->move($vp, $form, 'vp_review')->assertStatus(403);
    }

    public function test_submitting_without_any_indicator_is_rejected(): void
    {
        ['form' => $form, 'employee' => $employee] = $this->scenario();

        $form->outputs()->delete();

        $this->move($employee, $form, 'head_review')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add at least one success indicator before submitting.');
    }

    public function test_returning_a_form_requires_a_note_and_reopens_editing(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head] = $this->scenario();

        $this->move($employee, $form, 'head_review')->assertOk();

        $this->move($head, $form, 'returned')->assertStatus(422);

        $this->move($head, $form, 'returned', 'The evidence does not match the target.')->assertOk();

        $form->refresh();
        $this->assertSame('returned', $form->status);
        $this->assertTrue($form->isEditable());

        $log = PcrStatusLog::where('form_id', $form->id)->latest('id')->first();
        $this->assertSame('The evidence does not match the target.', $log->note);
        $this->assertSame($head->name, $log->performed_by_name);
    }

    public function test_a_returned_form_can_be_resubmitted(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head] = $this->scenario();

        $this->move($employee, $form, 'head_review')->assertOk();
        $this->move($head, $form, 'returned', 'Please attach the certificate.')->assertOk();
        $this->move($employee, $form, 'head_review')->assertOk();

        $this->assertSame('head_review', $form->fresh()->status);
    }

    public function test_another_employee_cannot_submit_someone_elses_form(): void
    {
        ['form' => $form] = $this->scenario();

        $stranger = User::factory()->create(['role' => 'employee']);

        $this->move($stranger, $form, 'head_review')->assertStatus(403);
    }

    public function test_admin_may_move_a_form_at_any_stage(): void
    {
        ['form' => $form] = $this->scenario();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->move($admin, $form, 'head_review')->assertOk();
        $this->move($admin, $form, 'vp_review')->assertOk();
        $this->move($admin, $form, 'qa_rating')->assertOk();
    }

    public function test_qa_can_close_a_rated_form(): void
    {
        ['form' => $form, 'qa' => $qa] = $this->scenario();

        $form->update(['status' => 'rated']);

        $this->move($qa, $form, 'final')->assertOk();
        $this->assertSame('final', $form->fresh()->status);
    }

    public function test_president_can_close_a_rated_form(): void
    {
        ['form' => $form] = $this->scenario();

        $president = User::factory()->create(['role' => 'president']);

        $form->update(['status' => 'rated']);

        $this->move($president, $form, 'final')->assertOk();
        $this->assertSame('final', $form->fresh()->status);
    }

    public function test_the_owner_cannot_close_their_own_rated_form(): void
    {
        ['form' => $form, 'employee' => $employee] = $this->scenario();

        $form->update(['status' => 'rated']);

        $this->move($employee, $form, 'final')->assertStatus(403);
    }

    public function test_a_final_form_cannot_move_anywhere(): void
    {
        ['form' => $form, 'qa' => $qa] = $this->scenario();

        $form->update(['status' => 'final']);

        $this->move($qa, $form, 'qa_rating')->assertStatus(409);
    }
}
