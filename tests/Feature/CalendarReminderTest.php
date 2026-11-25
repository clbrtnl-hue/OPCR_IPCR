<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Notification;
use App\Models\OrgUnitReviewer;
use App\Models\User;
use App\Services\CalendarReminderService;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

class CalendarReminderTest extends PmsTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_only_me_event_reminds_its_owner_the_day_before_and_on_the_day(): void
    {
        $this->makeOrganization();
        $owner = User::factory()->create(['role' => 'employee']);
        $event = $this->event($owner, [
            'title'      => 'Read the papers',
            'starts_at'  => '2026-11-26 15:00:00',
            'ends_at'    => '2026-11-26 16:00:00',
            'all_day'    => false,
            'visibility' => 'only_me',
        ]);

        Carbon::setTestNow('2026-11-25 07:00:00');
        app(CalendarReminderService::class)->send();
        app(CalendarReminderService::class)->send();

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('title', 'Tomorrow: Read the papers')->count());
        $this->assertSame(
            "/attendance?tab=calendar&event={$event->id}&on=2026-11-26&when=tomorrow",
            Notification::where('title', 'Tomorrow: Read the papers')->value('link')
        );
        $this->assertSame('3:00 PM – 4:00 PM', Notification::where('title', 'Tomorrow: Read the papers')->value('body'));

        Carbon::setTestNow('2026-11-26 07:00:00');
        app(CalendarReminderService::class)->send();

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('title', 'Today: Read the papers')->count());
    }

    public function test_an_all_day_event_is_reminded_on_the_morning_of_that_day(): void
    {
        $this->makeOrganization();
        $owner = User::factory()->create(['role' => 'employee']);
        $this->event($owner, [
            'title'      => 'Foundation day',
            'starts_at'  => '2026-11-26 00:00:00',
            'ends_at'    => '2026-11-26 23:59:59',
            'all_day'    => true,
            'visibility' => 'only_me',
        ]);

        Carbon::setTestNow('2026-11-25 07:00:00');
        $this->assertSame(0, app(CalendarReminderService::class)->send());

        Carbon::setTestNow('2026-11-26 07:00:00');
        app(CalendarReminderService::class)->send();

        $notice = Notification::where('user_id', $owner->id)->where('title', 'Today: Foundation day')->first();
        $this->assertNotNull($notice);
        $this->assertSame('All day', $notice->body);
    }

    public function test_a_shared_event_reminds_everyone_who_can_see_it_including_the_owner(): void
    {
        $this->makeOrganization();
        $office = $this->makeUnit(['name' => 'College of Technology', 'code' => 'COT']);
        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG']);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $office->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);
        $outsider = User::factory()->create(['role' => 'employee', 'org_unit_id' => $elsewhere->id]);
        $president = User::factory()->create(['role' => 'president']);
        $inactive = User::factory()->create(['role' => 'employee', 'status' => 'inactive', 'org_unit_id' => $office->id]);

        OrgUnitReviewer::create([
            'org_unit_id' => $office->id,
            'slot'        => 'head_user_id',
            'user_id'     => $head->id,
        ]);

        $meeting = $this->event($head, [
            'title'      => 'Department meeting',
            'starts_at'  => '2026-11-26 09:00:00',
            'ends_at'    => '2026-11-26 10:00:00',
            'all_day'    => false,
            'visibility' => 'everyone',
        ]);

        $picked = $this->event($head, [
            'title'      => 'Private consult',
            'starts_at'  => '2026-11-26 13:00:00',
            'ends_at'    => '2026-11-26 13:30:00',
            'all_day'    => false,
            'visibility' => 'selected',
        ]);
        $picked->viewers()->sync([$staff->id, $inactive->id]);

        Carbon::setTestNow('2026-11-25 07:00:00');
        app(CalendarReminderService::class)->send();

        foreach ([$head->id, $staff->id] as $userId) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $userId,
                'title'   => 'Tomorrow: Department meeting',
                'link'    => "/attendance?tab=calendar&event={$meeting->id}&on=2026-11-26&when=tomorrow",
            ]);
        }

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $president->id,
            'title'   => 'Tomorrow: Department meeting',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $outsider->id,
            'title'   => 'Tomorrow: Department meeting',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'title'   => 'Tomorrow: Private consult',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $staff->id,
            'title'   => 'Tomorrow: Private consult',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $inactive->id,
            'title'   => 'Tomorrow: Private consult',
        ]);
    }

    public function test_a_moved_date_is_reminded_again_and_a_finished_event_is_not(): void
    {
        $this->makeOrganization();
        $owner = User::factory()->create(['role' => 'employee']);
        $event = $this->event($owner, [
            'title'      => 'Site visit',
            'starts_at'  => '2026-11-26 15:00:00',
            'ends_at'    => '2026-11-26 16:00:00',
            'all_day'    => false,
            'visibility' => 'only_me',
        ]);

        Carbon::setTestNow('2026-11-25 07:00:00');
        app(CalendarReminderService::class)->send();

        $event->update([
            'starts_at' => '2026-12-02 15:00:00',
            'ends_at'   => '2026-12-02 16:00:00',
        ]);

        $this->assertSame(0, app(CalendarReminderService::class)->send());

        Carbon::setTestNow('2026-12-01 07:00:00');
        app(CalendarReminderService::class)->send();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'link'    => "/attendance?tab=calendar&event={$event->id}&on=2026-12-02&when=tomorrow",
        ]);

        $this->event($owner, [
            'title'      => 'Early call',
            'starts_at'  => '2026-12-01 06:00:00',
            'ends_at'    => '2026-12-01 06:30:00',
            'all_day'    => false,
            'visibility' => 'only_me',
        ]);

        Carbon::setTestNow('2026-12-01 07:00:00');
        app(CalendarReminderService::class)->send();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->id,
            'title'   => 'Today: Early call',
        ]);
    }

    private function event(User $owner, array $attributes): CalendarEvent
    {
        return CalendarEvent::create(array_merge($attributes, [
            'created_by' => $owner->id,
        ]));
    }
}
