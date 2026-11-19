<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\PcrPeriodSummary;
use App\Models\User;
use Tests\PmsTestCase;

class HrClosingTest extends PmsTestCase
{
    private function office(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit(['name' => 'Human Resource Management Office', 'code' => 'HRMO']);
        $year = $this->makeSchoolYear([
            'label'      => '2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ]);
        $first  = $this->makePeriod($year, 1);
        $second = $this->makePeriod($year, 2, 'upcoming');
        $first->update(['label' => 'January to June']);
        $second->update(['label' => 'July to December', 'is_active' => false]);

        $hr = User::factory()->create(['role' => 'hr', 'org_unit_id' => $unit->id, 'name' => 'HR Head']);
        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'name' => 'Ada Staff']);

        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'status'         => 'published',
        ]);

        return compact('unit', 'year', 'first', 'second', 'hr', 'qa', 'staff', 'opcr');
    }

    private function score(PcrForm $form, int $periodId, float $average): void
    {
        $form->update(['status' => 'rated', 'rating_period_id' => $periodId]);

        PcrPeriodSummary::create([
            'form_id'          => $form->id,
            'rating_period_id' => $periodId,
            'final_average'    => $average,
            'adjectival'       => 'Very Satisfactory',
        ]);
    }

    public function test_hr_sees_the_average_and_waits_until_the_opcr_is_rated(): void
    {
        ['unit' => $unit, 'year' => $year, 'first' => $first, 'hr' => $hr, 'staff' => $staff] = $this->office();

        $form = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->score($form, $first->id, 4.0);

        $this->actingAsUser($hr);

        $office = $this->getJson('/api/hr/closing')->assertSuccessful()->json('offices.0');

        $this->assertEquals(4.0, $office['average']);
        $this->assertFalse($office['can_close']);
        $this->assertSame('Waiting for the college OPCR rating.', $office['reason']);
    }

    public function test_hr_closes_an_office_when_the_average_is_within_the_opcr(): void
    {
        ['unit' => $unit, 'year' => $year, 'first' => $first, 'second' => $second, 'hr' => $hr, 'staff' => $staff, 'opcr' => $opcr] = $this->office();

        $january = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $july = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->score($january, $first->id, 3.5);
        $this->score($july, $second->id, 4.0);
        $this->score($opcr, $second->id, 4.0);

        $this->actingAsUser($hr);

        $office = $this->getJson('/api/hr/closing')->assertSuccessful()->json('offices.0');
        $this->assertEquals(3.75, $office['average']);
        $this->assertTrue($office['can_close']);

        $this->postJson("/api/hr/closing/{$unit->id}", ['school_year_id' => $year->id])->assertSuccessful();

        $this->assertSame('final', $january->fresh()->status);
        $this->assertSame('final', $july->fresh()->status);
        $this->getJson('/api/hr/closing')->assertSuccessful()->assertJsonCount(0, 'offices');
    }

    public function test_hr_cannot_close_an_office_above_the_opcr_and_can_send_it_back(): void
    {
        ['unit' => $unit, 'year' => $year, 'first' => $first, 'second' => $second, 'hr' => $hr, 'qa' => $qa, 'staff' => $staff, 'opcr' => $opcr] = $this->office();

        $january = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $july = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->score($january, $first->id, 4.6);
        $this->score($july, $second->id, 4.8);
        $this->score($opcr, $second->id, 4.0);

        $this->actingAsUser($hr);

        $office = $this->getJson('/api/hr/closing')->assertSuccessful()->json('offices.0');
        $this->assertTrue($office['over']);
        $this->assertFalse($office['can_close']);

        $this->postJson("/api/hr/closing/{$unit->id}", ['school_year_id' => $year->id])
            ->assertStatus(422);

        $this->postJson("/api/pcr-forms/{$january->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $january->fresh()->status);

        $this->actingAsUser($qa);
        $ids = array_column($this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json(), 'id');
        $this->assertContains($january->id, $ids);
    }

    public function test_a_missing_period_keeps_the_office_open(): void
    {
        ['unit' => $unit, 'year' => $year, 'first' => $first, 'second' => $second, 'hr' => $hr, 'staff' => $staff, 'opcr' => $opcr] = $this->office();

        $january = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->score($january, $first->id, 3.0);
        $this->score($opcr, $second->id, 4.0);

        $this->actingAsUser($hr);

        $office = $this->getJson('/api/hr/closing')->assertSuccessful()->json('offices.0');

        $this->assertFalse($office['can_close']);
        $this->assertSame('1 person does not have an IPCR for every period.', $office['reason']);
    }

    public function test_qa_cannot_open_the_closing_list(): void
    {
        ['qa' => $qa] = $this->office();

        $this->actingAsUser($qa);

        $this->getJson('/api/hr/closing')->assertStatus(403);
    }
}
