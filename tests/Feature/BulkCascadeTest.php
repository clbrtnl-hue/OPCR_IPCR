<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PcrIndicator;
use App\Models\PcrTargetAssignment;
use App\Models\User;
use Tests\PmsTestCase;

class BulkCascadeTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $unit    = $this->makeUnit();
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year, 1);
        $period2 = $this->makePeriod($year, 2);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
            'user_id' => null,
        ]);

        $output = PcrTargetAssignment::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'Research']);

        $lines = collect(['Publish 25 articles.', 'Run 4 research fora.', 'Fund 3 grants.'])
            ->map(fn ($text, $index) => PcrIndicator::create([
                'output_id' => $output->id, 'description' => $text, 'sort_order' => $index,
            ]));

        $head    = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        return compact('unit', 'year', 'period', 'period2', 'opcr', 'output', 'lines', 'head', 'faculty', 'president');
    }

    public function test_every_line_reaches_every_person_in_one_call(): void
    {
        ['opcr' => $opcr, 'head' => $head, 'faculty' => $faculty, 'period' => $period] = $this->college();

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", [
            'user_ids' => [$head->id, $faculty->id],
        ])
            ->assertStatus(201)
            ->assertJsonPath('assigned', 6)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('lines', 3)
            ->assertJsonPath('people', 2);

        foreach ([$head, $faculty] as $person) {
            $form = \App\Models\PcrForm::where('type', 'ipcr')
                ->where('user_id', $person->id)
                ->where('rating_period_id', $period->id)
                ->first();

            $this->assertNotNull($form, 'The cascade should open an IPCR for the assignee.');
            $this->assertSame('draft', $form->status);

            $this->assertSame(
                3,
                PcrTargetAssignment::where('user_id', $person->id)->count()
            );
        }
    }

    public function test_it_raises_one_notification_per_person_not_one_per_line(): void
    {
        ['opcr' => $opcr, 'head' => $head] = $this->college();

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", ['user_ids' => [$head->id]])
            ->assertStatus(201);

        $this->assertSame(
            1,
            Notification::where('user_id', $head->id)->where('type', 'assignment')->count()
        );
    }

    public function test_only_the_named_lines_are_handed_out(): void
    {
        ['opcr' => $opcr, 'lines' => $lines, 'head' => $head] = $this->college();

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", [
            'user_ids'      => [$head->id],
            'indicator_ids' => [$lines[0]->id, $lines[2]->id],
        ])
            ->assertStatus(201)
            ->assertJsonPath('assigned', 2)
            ->assertJsonPath('lines', 2);

        $this->assertDatabaseHas('pcr_target_assignments', ['indicator_id' => $lines[0]->id, 'user_id' => $head->id]);
        $this->assertDatabaseMissing('pcr_target_assignments', ['indicator_id' => $lines[1]->id, 'user_id' => $head->id]);
    }

    public function test_a_line_from_another_form_is_refused(): void
    {
        ['opcr' => $opcr, 'head' => $head, 'year' => $year, 'unit' => $unit] = $this->college();

        $other     = $this->makeForm(['org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $head->id]);
        $otherLine = $this->makeIndicator($other);

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", [
            'user_ids'      => [$head->id],
            'indicator_ids' => [$otherLine->id],
        ])->assertStatus(422);
    }

    public function test_running_it_twice_skips_what_is_already_out(): void
    {
        ['opcr' => $opcr, 'head' => $head] = $this->college();

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", ['user_ids' => [$head->id]])
            ->assertJsonPath('assigned', 3);

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", ['user_ids' => [$head->id]])
            ->assertJsonPath('assigned', 0)
            ->assertJsonPath('skipped', 3);
    }

    public function test_each_period_is_cascaded_separately(): void
    {
        ['opcr' => $opcr, 'head' => $head, 'period' => $period, 'period2' => $period2] = $this->college();

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", [
            'user_ids' => [$head->id], 'rating_period_id' => $period->id,
        ])->assertJsonPath('assigned', 3);

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", [
            'user_ids' => [$head->id], 'rating_period_id' => $period2->id,
        ])->assertJsonPath('assigned', 3);

        $this->assertSame(2, \App\Models\PcrForm::where('type', 'ipcr')->where('user_id', $head->id)->count());
    }

    public function test_a_period_from_another_year_is_refused(): void
    {
        ['opcr' => $opcr, 'head' => $head] = $this->college();

        $otherYear   = $this->makeSchoolYear(['label' => 'SY 2027-2028', 'is_active' => false, 'start_date' => '2027-08-01', 'end_date' => '2028-07-31']);
        $otherPeriod = $this->makePeriod($otherYear, 1);

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", [
            'user_ids' => [$head->id], 'rating_period_id' => $otherPeriod->id,
        ])->assertStatus(422);
    }

    public function test_a_terminal_role_cannot_cascade(): void
    {
        ['opcr' => $opcr, 'head' => $head, 'faculty' => $faculty, 'year' => $year, 'unit' => $unit] = $this->college();

        $own = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->makeIndicator($own);

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$own->id}/cascade", ['user_ids' => [$head->id]])
            ->assertStatus(403);
    }

    public function test_a_head_from_another_office_cannot_cascade_this_opcr(): void
    {
        ['opcr' => $opcr, 'faculty' => $faculty] = $this->college();

        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG', 'type' => 'office']);
        $outsider  = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $elsewhere->id]);

        $this->actingAsUser($outsider);

        $this->postJson("/api/pcr-forms/{$opcr->id}/cascade", ['user_ids' => [$faculty->id]])
            ->assertStatus(403);
    }

    public function test_an_empty_form_has_nothing_to_hand_out(): void
    {
        ['head' => $head, 'year' => $year, 'unit' => $unit] = $this->college();

        $empty = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
            'status' => 'draft', 'user_id' => null,
        ]);

        $this->actingAsRole('president');

        $this->postJson("/api/pcr-forms/{$empty->id}/cascade", ['user_ids' => [$head->id]])
            ->assertStatus(409);
    }
}
