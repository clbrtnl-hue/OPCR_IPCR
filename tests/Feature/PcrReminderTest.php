<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\User;
use App\Services\PcrReminderService;
use Tests\PmsTestCase;

class PcrReminderTest extends PmsTestCase
{
    public function test_it_warns_once_when_a_commitment_is_due_soon_today_or_overdue(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $year = $this->makeSchoolYear();
        $form = $this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
            'status'         => 'draft',
        ]);

        $soon = $this->makeIndicator($form, 'core', ['target_date' => now()->addDays(3)->toDateString()]);
        $today = $this->makeIndicator($form, 'core', ['target_date' => now()->toDateString()]);
        $late = $this->makeIndicator($form, 'core', ['target_date' => now()->subDay()->toDateString()]);
        $this->makeIndicator($form, 'core', [
            'target_date'     => now()->subDay()->toDateString(),
            'progress_status' => 'completed',
        ]);

        $reminders = app(PcrReminderService::class);
        $reminders->send();
        $reminders->send();

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('type', 'due_soon')->count());
        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('type', 'due_today')->count());
        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('type', 'overdue')->count());
        $this->assertSame("/forms/{$form->id}?line={$soon->id}&when=soon", Notification::where('type', 'due_soon')->value('link'));
        $this->assertSame("/forms/{$form->id}?line={$today->id}&when=today", Notification::where('type', 'due_today')->value('link'));
        $this->assertSame("/forms/{$form->id}?line={$late->id}&when=overdue", Notification::where('type', 'overdue')->where('user_id', $owner->id)->value('link'));
        $this->assertSame(1, Notification::where('user_id', $head->id)->where('type', 'overdue')->count());
        $this->assertDatabaseMissing('notifications', ['user_id' => $owner->id, 'type' => 'overdue', 'body' => 'They are past their target date.']);
    }

    public function test_it_does_not_ping_the_president_when_an_ipcr_is_due(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $president = User::factory()->create(['role' => 'president']);
        $year = $this->makeSchoolYear();
        $form = $this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $president->id,
        ]);
        $this->makeIndicator($form, 'core', ['target_date' => now()->subDay()->toDateString()]);

        app(PcrReminderService::class)->send();

        $this->assertDatabaseMissing('notifications', ['user_id' => $president->id]);
    }

    public function test_it_reminds_a_draft_owner_and_a_reviewer_when_the_period_is_closing(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $waiting = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $rating = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $qa = User::factory()->create(['role' => 'qa']);
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $period->update([
            'opens_at'  => now()->subMonth()->toDateString(),
            'closes_at' => now()->addDays(4)->toDateString(),
            'status'    => 'open',
        ]);

        $draft = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $owner->id,
            'status'           => 'draft',
        ]);
        PcrForm::create([
            'type'             => 'ipcr',
            'status'           => 'head_review',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $waiting->id,
            'head_reviewer_id' => $head->id,
        ]);
        PcrForm::create([
            'type'             => 'ipcr',
            'status'           => 'qa_rating',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $rating->id,
        ]);

        app(PcrReminderService::class)->send();
        app(PcrReminderService::class)->send();

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('type', 'period')->count());
        $this->assertSame("/forms/{$draft->id}?period={$period->id}", Notification::where('user_id', $owner->id)->value('link'));
        $this->assertSame(1, Notification::where('user_id', $head->id)->where('type', 'period')->count());
        $this->assertSame(1, Notification::where('user_id', $qa->id)->where('type', 'period')->count());
    }

    public function test_it_reminds_qa_once_that_a_rated_form_still_needs_to_be_closed(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'name' => 'Ana Cruz']);
        $closed = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $qa = User::factory()->create(['role' => 'qa']);
        $inactive = User::factory()->create(['role' => 'qa', 'status' => 'inactive']);
        $president = User::factory()->create(['role' => 'president']);
        $year = $this->makeSchoolYear();

        $open = $this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
            'status'         => 'rated',
        ]);
        $this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $closed->id,
            'status'         => 'final',
        ]);

        $reminders = app(PcrReminderService::class);
        $reminders->send();
        $reminders->send();

        $notice = Notification::where('user_id', $qa->id)->where('type', 'unclosed')->get();
        $this->assertCount(1, $notice);
        $this->assertSame($open->id, $notice->first()->form_id);
        $this->assertSame("/forms/{$open->id}", $notice->first()->link);
        $this->assertStringContainsString('Ana Cruz', $notice->first()->body);
        $this->assertDatabaseMissing('notifications', ['user_id' => $inactive->id, 'type' => 'unclosed']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $president->id, 'type' => 'unclosed']);
    }
}
