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
        $this->assertSame(
            '/reports?tab=commitments&late=1',
            Notification::where('user_id', $head->id)->where('type', 'overdue')->value('link')
        );
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

    public function test_a_replaced_commitment_does_not_raise_a_deadline(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $owner = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $year = $this->makeSchoolYear();
        $form = $this->makeForm([
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'user_id'        => $owner->id,
            'status'         => 'draft',
        ]);
        $this->makeIndicator($form, 'core', [
            'target_date'   => now()->subDay()->toDateString(),
            'closed_at'     => now(),
            'closed_reason' => 'target_replaced',
        ]);

        app(PcrReminderService::class)->send();

        $this->assertDatabaseMissing('notifications', ['user_id' => $owner->id]);
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
        $waitingForm = PcrForm::create([
            'type'             => 'ipcr',
            'status'           => 'head_review',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $waiting->id,
            'head_reviewer_id' => $head->id,
        ]);
        $ratingForm = PcrForm::create([
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
        $this->assertSame(
            "/review-queue?tab=rating&form={$waitingForm->id}",
            Notification::where('user_id', $head->id)->where('type', 'period')->value('link')
        );
        $this->assertSame(1, Notification::where('user_id', $qa->id)->where('type', 'period')->count());
        $this->assertSame(
            "/review-queue?tab=rating&form={$ratingForm->id}",
            Notification::where('user_id', $qa->id)->where('type', 'period')->value('link')
        );
    }

    public function test_an_inactive_account_is_not_chased_or_handed_to_the_head(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit();
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);
        $left = User::factory()->create([
            'role' => 'employee', 'org_unit_id' => $unit->id, 'status' => 'inactive', 'name' => 'Richard Gutierrez',
        ]);
        $ratedLeft = User::factory()->create([
            'role' => 'employee', 'org_unit_id' => $unit->id, 'status' => 'inactive',
        ]);
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
            'user_id'          => $left->id,
            'status'           => 'draft',
        ]);
        $this->makeIndicator($draft, 'core', ['target_date' => now()->subDay()->toDateString()]);
        $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $ratedLeft->id,
            'status'           => 'rated',
        ]);
        $waiting = User::factory()->create([
            'role' => 'employee', 'org_unit_id' => $unit->id, 'status' => 'inactive',
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

        app(PcrReminderService::class)->send();

        $this->assertDatabaseMissing('notifications', ['user_id' => $left->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $head->id]);
    }
}
