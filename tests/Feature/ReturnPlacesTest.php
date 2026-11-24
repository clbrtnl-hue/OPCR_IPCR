<?php

namespace Tests\Feature;

use App\Models\PcrReturnMark;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * A return from commitment approval names the cells. A return from rating
 * still carries one note for the whole form.
 */
class ReturnPlacesTest extends PmsTestCase
{
    public function test_an_ipcr_commitment_return_names_the_cells(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $approver = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'org_unit_id'            => $unit->id,
            'school_year_id'         => $year->id,
            'rating_period_id'       => $period->id,
            'user_id'                => $owner->id,
            'status'                 => 'commitment_review',
            'commitment_approver_id' => $approver->id,
        ]);
        $line = $this->makeIndicator($form, 'core', ['rating_period_id' => $period->id]);
        $other = $this->makeIndicator($this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
        ]), 'core');

        $this->actingAsUser($approver);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'note'   => 'Fix the first line.',
        ])->assertStatus(422);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [[
                'indicator_id' => $line->id,
                'field'        => 'allotted_budget',
                'note'         => 'Drop the budget.',
            ]],
        ])->assertStatus(422);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [[
                'indicator_id' => $other->id,
                'field'        => 'description',
                'note'         => 'This line is on another form.',
            ]],
        ])->assertStatus(422);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [
                [
                    'output_id' => $line->output_id,
                    'field'     => 'title',
                    'note'      => 'Name the program.',
                ],
                [
                    'indicator_id' => $line->id,
                    'field'        => 'target_date',
                    'note'         => 'Move the date to June.',
                ],
            ],
        ])->assertOk();

        $this->assertSame('returned', $form->fresh()->status);
        $this->assertSame(2, PcrReturnMark::where('form_id', $form->id)->count());

        $this->actingAsUser($owner);
        $marks = $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->json('return_marks');

        $this->assertSame(['title', 'target_date'], array_column($marks, 'field'));
        $this->assertSame('Name the program.', $marks[0]['note']);
        $this->assertSame('Research', $marks[0]['previous_value']);
        $this->assertSame('', $marks[1]['previous_value']);
    }

    public function test_an_opcr_approval_return_can_mark_budget_and_accountable(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'status'         => 'qa_approval',
        ]);
        $line = $this->makeIndicator($opcr, 'core');

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", [
            'status' => 'returned',
            'fixes'  => [
                [
                    'indicator_id' => $line->id,
                    'field'        => 'allotted_budget',
                    'note'         => 'The budget does not match the activity.',
                ],
                [
                    'indicator_id' => $line->id,
                    'field'        => 'accountable',
                    'note'         => 'Name the office that delivers this.',
                ],
            ],
        ])->assertOk();

        $this->assertSame(
            ['allotted_budget', 'accountable'],
            PcrReturnMark::where('form_id', $opcr->id)->orderBy('id')->pluck('field')->all()
        );
    }

    public function test_a_return_from_rating_marks_the_write_up(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $form = $this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
            'status'         => 'qa_rating',
        ]);
        $line = $this->makeIndicator($form, 'core');

        $this->actingAsRole('qa', ['org_unit_id' => $unit->id]);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'note'   => 'The evidence does not match the target.',
        ])->assertStatus(422);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [[
                'indicator_id' => $line->id,
                'field'        => 'description',
                'note'         => 'Rewrite the target.',
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A return from review can only mark the accomplishment or the evidence.');

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [
                [
                    'indicator_id' => $line->id,
                    'field'        => 'actual_accomplishment',
                    'note'         => 'The narrative does not match the target.',
                ],
                [
                    'indicator_id' => $line->id,
                    'field'        => 'evidence',
                    'note'         => 'Attach the source.',
                ],
            ],
        ])->assertOk();

        $this->actingAsUser($owner);
        $marks = $this->getJson("/api/pcr-forms/{$form->id}")->assertOk()->json('return_marks');

        $this->assertSame(['actual_accomplishment', 'evidence'], array_column($marks, 'field'));
    }

    public function test_return_marks_stay_until_the_next_approval(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $approver = User::factory()->create(['role' => 'program_head', 'name' => 'Head Reyes']);
        $unit = $this->makeUnit(['head_user_id' => $approver->id]);
        $approver->update(['org_unit_id' => $unit->id]);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'org_unit_id'            => $unit->id,
            'school_year_id'         => $year->id,
            'rating_period_id'       => $period->id,
            'user_id'                => $owner->id,
            'status'                 => 'commitment_review',
            'commitment_approver_id' => $approver->id,
        ]);
        $line = $this->makeIndicator($form, 'core', ['rating_period_id' => $period->id]);

        $this->actingAsUser($approver);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'fixes'  => [[
                'indicator_id' => $line->id,
                'field'        => 'description',
                'note'         => 'Tighten the target.',
            ]],
        ])->assertOk();

        // Commitments can only go out once the college OPCR is published.
        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);

        $original = $line->description;
        $line->update(['description' => 'A tighter target for the year.']);

        $this->actingAsUser($owner);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'commitment_review',
        ])->assertOk();

        $marks = $this->getJson("/api/pcr-forms/{$form->id}")->assertOk()->json('return_marks');
        $this->assertSame(['description'], array_column($marks, 'field'));
        // The note stays the approver's ask. The snapshot stays the owner's old wording.
        $this->assertSame(['Tighten the target.'], array_column($marks, 'note'));
        $this->assertSame([$original], array_column($marks, 'previous_value'));

        $this->actingAsUser($approver);
        $marks = $this->getJson("/api/pcr-forms/{$form->id}")->assertOk()->json('return_marks');
        $this->assertSame(['description'], array_column($marks, 'field'));
        $this->assertSame(['Tighten the target.'], array_column($marks, 'note'));

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'commitments_approved',
        ])->assertOk();

        $this->assertSame([], $this->getJson("/api/pcr-forms/{$form->id}")->assertOk()->json('return_marks'));
    }
}
