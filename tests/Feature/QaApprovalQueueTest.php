<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Tests\PmsTestCase;

class QaApprovalQueueTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit();
        $year = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $qa        = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);
        $otherQa   = User::factory()->create(['role' => 'qa', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
        ]);
        $this->makeIndicator($opcr, 'core');

        return compact('unit', 'year', 'president', 'qa', 'otherQa', 'opcr');
    }

    public function test_qa_queue_holds_approvals_and_ratings(): void
    {
        ['unit' => $unit, 'year' => $year, 'qa' => $qa, 'opcr' => $opcr] = $this->college();

        $opcr->update(['status' => 'qa_approval']);

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $toRate   = $this->makeForm([
            'org_unit_id' => $unit->id, 'school_year_id' => $year->id,
            'user_id' => $employee->id, 'status' => 'qa_rating',
        ]);
        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $this->makeUnit(['name' => 'Library', 'code' => 'LIB'])->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);

        $this->actingAsUser($qa);

        $ids = collect($this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json())->pluck('id')->sort()->values();

        $this->assertSame(collect([$opcr->id, $toRate->id])->sort()->values()->all(), $ids->all());
    }

    public function test_sending_the_opcr_for_approval_notifies_every_qa(): void
    {
        ['president' => $president, 'qa' => $qa, 'otherQa' => $otherQa, 'opcr' => $opcr] = $this->college();

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'qa_approval'])->assertSuccessful();

        foreach ([$qa, $otherQa] as $reviewer) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $reviewer->id, 'type' => 'approval', 'form_id' => $opcr->id,
            ]);
        }
    }

    public function test_approving_the_opcr_notifies_the_president(): void
    {
        ['president' => $president, 'qa' => $qa, 'opcr' => $opcr] = $this->college();

        $opcr->update(['status' => 'qa_approval']);

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", ['status' => 'approved'])->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $president->id, 'type' => 'approved', 'form_id' => $opcr->id,
        ]);
    }

    public function test_returning_the_opcr_notifies_the_president(): void
    {
        ['president' => $president, 'qa' => $qa, 'opcr' => $opcr] = $this->college();

        $opcr->update(['status' => 'qa_approval']);

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$opcr->id}/status", [
            'status' => 'returned', 'note' => 'Targets need a measure.',
        ])->assertSuccessful();

        $note = Notification::where('user_id', $president->id)->where('type', 'returned')->first();

        $this->assertNotNull($note);
        $this->assertSame('Targets need a measure.', $note->body);
    }
}
