<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\OrgUnitReviewer;
use App\Models\PcrOutput;
use App\Models\User;
use Tests\PmsTestCase;

class CalendarActivityTest extends PmsTestCase
{
    public function test_a_head_can_add_a_category_and_score_it_from_event_days(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $unit = $this->makeUnit();
        $head = User::factory()->create(['role' => 'program_head', 'name' => 'Office Head', 'org_unit_id' => $unit->id]);
        $staff = User::factory()->create(['role' => 'employee', 'name' => 'Faculty Member', 'org_unit_id' => $unit->id]);
        $employee = User::factory()->create(['role' => 'employee', 'name' => 'Other Staff', 'org_unit_id' => $unit->id]);

        OrgUnitReviewer::create([
            'org_unit_id' => $unit->id,
            'slot' => 'director_review',
            'user_id' => $head->id,
        ]);

        $this->actingAsUser($employee);
        $this->postJson('/api/attendance-categories', ['name' => 'Accreditation'])->assertStatus(403);

        $this->actingAsUser($head);
        $category = $this->postJson('/api/attendance-categories', ['name' => 'Accreditation'])
            ->assertCreated()
            ->json('category');

        $event = $this->postJson('/api/calendar-events', [
            'title'            => 'Accreditation workshop',
            'starts_at'        => '2026-11-24',
            'ends_at'          => '2026-11-26',
            'all_day'          => true,
            'visibility'       => 'everyone',
            'category_id'      => $category['id'],
            'rating_period_id' => $period->id,
        ])->assertCreated();

        $eventId = $event->json('event.id');

        $this->putJson("/api/calendar-events/{$eventId}/marks", [
            'user_id' => $staff->id,
            'day'     => '2026-11-24',
            'status'  => 'present',
        ])->assertOk();
        $this->putJson("/api/calendar-events/{$eventId}/marks", [
            'user_id' => $staff->id,
            'day'     => '2026-11-25',
            'status'  => 'present',
        ])->assertOk();
        $this->putJson("/api/calendar-events/{$eventId}/marks", [
            'user_id' => $staff->id,
            'day'     => '2026-11-26',
            'status'  => 'excused',
        ])->assertOk();

        $listed = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json())
            ->firstWhere('id', $eventId);
        $this->assertEqualsCanonicalizing(
            [
                ['user_id' => $staff->id, 'day' => '2026-11-24'],
                ['user_id' => $staff->id, 'day' => '2026-11-25'],
            ],
            collect($listed['present'])->map(fn ($person) => [
                'user_id' => $person['user_id'],
                'day' => $person['day'],
            ])->all()
        );

        $this->assertSame('present', AttendanceDay::where('user_id', $staff->id)->whereDate('day', '2026-11-24')->value('status'));
        $this->assertSame('leave', AttendanceDay::where('user_id', $staff->id)->whereDate('day', '2026-11-26')->value('status'));

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $staff->id,
            'status'         => 'draft',
        ]);
        $output = PcrOutput::create([
            'form_id'                => $form->id,
            'section'                => 'support',
            'title'                  => 'Preparation of accreditation documents',
            'attendance_category_id' => $category['id'],
        ]);

        $this->actingAsUser($staff);
        $shown = $this->getJson("/api/pcr-forms/{$form->id}")->assertOk();
        $key = 'c' . $category['id'];

        $shown->assertJsonPath('outputs.0.attendance_category', $key);
        $shown->assertJsonPath("attendance.{$period->id}.{$key}.present", 2);
        $shown->assertJsonPath("attendance.{$period->id}.{$key}.excused", 1);
        $shown->assertJsonPath("attendance.{$period->id}.{$key}.counted", 2);
        $shown->assertJsonPath("attendance.{$period->id}.{$key}.pct", 100);
        $shown->assertJsonPath("attendance.{$period->id}.{$key}.suggested", 5);
        $shown->assertJsonPath("attendance.{$period->id}.daily.present", 2);
        $shown->assertJsonPath("attendance.{$period->id}.daily.leave", 1);

        $this->assertNotNull($output->id);
    }

    public function test_an_event_mark_does_not_replace_leave_on_the_daily_sheet(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $unit = $this->makeUnit();
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        AttendanceDay::create([
            'user_id'          => $staff->id,
            'day'              => '2026-11-24',
            'rating_period_id' => $period->id,
            'marked_by'        => $head->id,
            'status'           => 'leave',
        ]);

        $this->actingAsUser($head);
        $category = $this->postJson('/api/attendance-categories', ['name' => 'Research forum'])->assertCreated()->json('category');
        $event = $this->postJson('/api/calendar-events', [
            'title'            => 'Research forum',
            'starts_at'        => '2026-11-24',
            'ends_at'          => '2026-11-24',
            'all_day'          => true,
            'visibility'       => 'selected',
            'user_ids'         => [$staff->id],
            'category_id'      => $category['id'],
            'rating_period_id' => $period->id,
        ])->assertCreated();

        $this->putJson('/api/calendar-events/' . $event->json('event.id') . '/marks', [
            'user_id' => $staff->id,
            'day'     => '2026-11-24',
            'status'  => 'absent',
        ])->assertOk();

        $this->assertSame('leave', AttendanceDay::where('user_id', $staff->id)->whereDate('day', '2026-11-24')->value('status'));

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $staff->id,
        ]);
        PcrOutput::create([
            'form_id'                => $form->id,
            'section'                => 'support',
            'title'                  => 'Research forum attendance',
            'attendance_category_id' => $category['id'],
        ]);

        $this->actingAsUser($staff);
        $key = 'c' . $category['id'];
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath("attendance.{$period->id}.{$key}.absent", 1)
            ->assertJsonPath("attendance.{$period->id}.{$key}.pct", 0)
            ->assertJsonPath("attendance.{$period->id}.daily.leave", 1)
            ->assertJsonPath("attendance.{$period->id}.daily.counted", 0);
    }

    public function test_only_me_events_are_not_counted(): void
    {
        $this->makeOrganization();
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $head = User::factory()->create(['role' => 'program_head']);

        $this->actingAsUser($head);
        $category = $this->postJson('/api/attendance-categories', ['name' => 'Notes'])->assertCreated()->json('category');

        $this->postJson('/api/calendar-events', [
            'title'            => 'Personal reminder',
            'starts_at'        => '2026-11-24',
            'ends_at'          => '2026-11-24',
            'all_day'          => true,
            'visibility'       => 'only_me',
            'category_id'      => $category['id'],
            'rating_period_id' => $period->id,
        ])->assertCreated()->assertJsonPath('event.category_id', null);
    }
}
