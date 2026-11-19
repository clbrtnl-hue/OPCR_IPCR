<?php

namespace Tests\Feature;

use App\Models\PcrStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;

class AppealTest extends PmsTestCase
{
    public function test_the_owner_appeals_and_the_president_sends_that_form_back_to_qa(): void
    {
        Mail::fake();
        ['owner' => $owner, 'president' => $president, 'hr' => $hr, 'form' => $form, 'other' => $other] = $this->closedPair();

        $this->actingAsUser($owner);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'appeal',
            'note'   => 'The evidence was already complete.',
        ])->assertOk();

        $this->assertSame('appeal', $form->fresh()->status);
        $this->assertSame('final', $other->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $president->id,
            'type'    => 'appeal',
            'form_id' => $form->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $hr->id,
            'form_id' => $form->id,
        ]);

        $this->actingAsUser($president);

        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertOk();
        $this->assertContains($form->id, collect($queue->json())->pluck('id')->all());

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'qa_rating',
            'note'   => 'The score should be reviewed.',
        ])->assertOk();

        $this->assertSame('qa_rating', $form->fresh()->status);
        $this->assertSame('final', $other->fresh()->status);
    }

    public function test_a_denied_appeal_stays_final_and_cannot_be_filed_again(): void
    {
        Mail::fake();
        ['owner' => $owner, 'president' => $president, 'form' => $form] = $this->closedPair();

        $this->actingAsUser($owner);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'appeal',
            'note'   => 'Please look again.',
        ])->assertOk();

        $this->actingAsUser($president);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'final',
            'note'   => 'The rating stands.',
        ])->assertOk();

        $this->assertSame('final', $form->fresh()->status);

        $this->actingAsUser($owner);
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'appeal',
            'note'   => 'I still disagree.',
        ])->assertStatus(422);
    }

    public function test_an_appeal_after_fifteen_days_is_refused(): void
    {
        Mail::fake();
        ['owner' => $owner, 'form' => $form] = $this->closedPair(now()->subDays(16));

        $this->actingAsUser($owner);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'appeal',
            'note'   => 'This is late.',
        ])->assertStatus(422)->assertJsonPath('message', 'The 15 days to appeal this rating have passed.');
    }

    public function test_somebody_else_cannot_file_the_appeal(): void
    {
        Mail::fake();
        ['form' => $form] = $this->closedPair();
        $colleague = User::factory()->create(['role' => 'employee', 'org_unit_id' => $form->org_unit_id]);

        $this->actingAsUser($colleague);

        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'appeal',
            'note'   => 'Not my form.',
        ])->assertStatus(403);
    }

    private function closedPair($closedAt = null): array
    {
        $this->makeOrganization();

        $year = $this->makeSchoolYear();
        $unit = $this->makeUnit(['name' => 'College of Teacher Education']);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'name' => 'Faculty Member']);
        $president = User::factory()->create(['role' => 'president', 'name' => 'College President']);
        $hr = User::factory()->create(['role' => 'hr', 'name' => 'HR Officer']);
        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $owner->id,
            'status'         => 'final',
        ]);
        $other = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id])->id,
            'status'         => 'final',
        ]);

        foreach ([$form, $other] as $closed) {
            $log = PcrStatusLog::create([
                'form_id'           => $closed->id,
                'from_status'       => 'rated',
                'to_status'         => 'final',
                'performed_by'      => $hr->id,
                'performed_by_name' => $hr->name,
            ]);
            $log->forceFill(['created_at' => $closedAt ?? now()->subDays(2)])->save();
        }

        return compact('owner', 'president', 'hr', 'form', 'other');
    }
}
