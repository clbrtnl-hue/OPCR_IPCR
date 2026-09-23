<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\PmsTestCase;

class NotificationTest extends PmsTestCase
{
    private function scenario(): array
    {
        $year = $this->makeSchoolYear();
        $this->makePeriod($year);
        $unit = $this->makeUnit();

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

        return compact('head', 'vp', 'qa', 'employee', 'form');
    }

    public function test_submitting_notifies_the_program_head(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head] = $this->scenario();

        Passport::actingAs($employee, [], 'api');
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'type'    => 'review',
        ]);
    }

    public function test_returning_notifies_the_writer_with_the_reason(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head] = $this->scenario();

        Passport::actingAs($employee, [], 'api');
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review']);

        Passport::actingAs($head, [], 'api');
        $this->postJson("/api/pcr-forms/{$form->id}/status", [
            'status' => 'returned',
            'note'   => 'Attach the acceptance letters.',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type'    => 'returned',
            'body'    => 'Attach the acceptance letters.',
        ]);
    }

    public function test_a_mention_notifies_only_the_tagged_person(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head] = $this->scenario();

        Passport::actingAs($head, [], 'api');

        $this->postJson('/api/pcr-comments', [
            'form_id'  => $form->id,
            'body'     => 'Please review this line.',
            'mentions' => [$employee->id],
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type'    => 'mention',
        ]);

        $this->assertDatabaseCount('pcr_comment_mentions', 1);
    }

    public function test_an_actor_is_never_notified_about_their_own_action(): void
    {
        ['form' => $form, 'employee' => $employee] = $this->scenario();

        Passport::actingAs($employee, [], 'api');

        $this->postJson('/api/pcr-comments', [
            'form_id'  => $form->id,
            'body'     => 'Noting my own progress.',
            'mentions' => [$employee->id],
        ])->assertStatus(201);

        $this->assertDatabaseMissing('notifications', ['user_id' => $employee->id]);
    }

    public function test_a_person_only_sees_their_own_notifications(): void
    {
        ['form' => $form, 'employee' => $employee, 'head' => $head] = $this->scenario();

        Notification::create([
            'user_id' => $employee->id,
            'type'    => 'comment',
            'title'   => 'For the employee',
            'form_id' => $form->id,
        ]);

        Notification::create([
            'user_id' => $head->id,
            'type'    => 'comment',
            'title'   => 'For the head',
            'form_id' => $form->id,
        ]);

        Passport::actingAs($employee, [], 'api');

        $response = $this->getJson('/api/notifications')->assertOk();

        $this->assertCount(1, $response->json('items'));
        $this->assertSame('For the employee', $response->json('items.0.title'));
        $this->assertSame(1, $response->json('unread'));
    }

    public function test_marking_all_read_clears_the_badge(): void
    {
        ['form' => $form, 'employee' => $employee] = $this->scenario();

        foreach (['a', 'b', 'c'] as $title) {
            Notification::create([
                'user_id' => $employee->id,
                'type'    => 'comment',
                'title'   => $title,
                'form_id' => $form->id,
            ]);
        }

        Passport::actingAs($employee, [], 'api');

        $this->assertSame(3, $this->getJson('/api/notifications')->json('unread'));

        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(0, $this->getJson('/api/notifications')->json('unread'));
    }

    public function test_mentionable_list_is_refused_to_an_outsider(): void
    {
        ['form' => $form] = $this->scenario();

        Passport::actingAs(User::factory()->create(['role' => 'employee']), [], 'api');

        $this->getJson("/api/pcr-forms/{$form->id}/mentionables")->assertStatus(403);
    }
}
