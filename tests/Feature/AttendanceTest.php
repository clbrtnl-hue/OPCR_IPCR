<?php

namespace Tests\Feature;

use App\Models\AttendanceRate;
use App\Models\PcrOutput;
use App\Models\User;
use Carbon\Carbon;
use Tests\PmsTestCase;

class AttendanceTest extends PmsTestCase
{
    public function test_a_head_records_weekdays_and_a_meeting_they_set(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->office();

        $this->actingAsUser($hr);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertOk()->assertJsonPath('rate.present', 1)->assertJsonPath('rate.pct', 100);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->copy()->addDay()->toDateString(),
            'status'           => 'absent',
        ])->assertOk();

        $leave = $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->copy()->addDays(2)->toDateString(),
            'status'           => 'leave',
        ])->assertOk();

        $leave->assertJsonPath('rate.present', 1);
        $leave->assertJsonPath('rate.absent', 1);
        $leave->assertJsonPath('rate.leave', 1);
        $leave->assertJsonPath('rate.counted', 2);
        $leave->assertJsonPath('rate.pct', 50);

        $saturday = $monday->copy()->addDays(5)->toDateString();
        $sunday = $monday->copy()->addDays(6)->toDateString();

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $saturday,
            'status'           => 'present',
        ])->assertOk()->assertJsonPath('rate.present', 2);

        $created = $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $staff->org_unit_id,
            'rating_period_id' => $period->id,
            'title'            => 'MANCOM',
            'category'         => 'meeting',
            'held_on'          => $monday->copy()->addDays(3)->toDateString(),
        ])->assertCreated();

        $thursday = $monday->copy()->addDays(3)->toDateString();

        $this->putJson("/api/attendance/meetings/{$created->json('meeting.id')}/marks", [
            'user_id' => $staff->id,
            'status'  => 'present',
        ])->assertOk();

        $sheet = $this->getJson("/api/attendance?rating_period_id={$period->id}&month={$monday->format('Y-m')}")
            ->assertOk();

        $sheet->assertJsonPath('days.0', $monday->toDateString());
        $this->assertSame('present', $sheet->json('daily')[$staff->id][$monday->toDateString()]);
        $this->assertSame('present', $sheet->json('daily')[$staff->id][$thursday]);
        $sheet->assertJsonPath('meetings.0.rate.pct', 100);
        $sheet->assertJsonPath('meetings.0.rate.excused', 0);
        $this->assertDatabaseHas('attendance_rates', [
            'user_id' => $staff->id,
            'rating_period_id' => $period->id,
            'scope' => 'daily',
            'pct' => 75,
        ]);
        $this->assertDatabaseHas('attendance_rates', [
            'user_id' => $staff->id,
            'rating_period_id' => $period->id,
            'scope' => 'activities',
            'pct' => 100,
        ]);
        $this->assertDatabaseHas('attendance_rates', [
            'user_id' => $staff->id,
            'rating_period_id' => $period->id,
            'scope' => 'meeting',
            'pct' => 100,
        ]);
        $this->assertSame(100, $sheet->json('category_rates')[$staff->id]['meeting']['pct']);
        $this->assertNull($sheet->json('category_rates')[$staff->id]['training']['pct']);
        $this->assertNull($sheet->json('category_rates')[$staff->id]['school']['pct']);
        $this->assertDatabaseHas('attendance_meetings', [
            'title' => 'MANCOM',
            'pct' => 100,
        ]);
        $this->assertContains($saturday, $sheet->json('days'));
        $this->assertContains($sunday, $sheet->json('days'));
        $sheet->assertJsonPath('meetings.0.title', 'MANCOM');
    }

    public function test_someone_who_does_not_head_the_office_cannot_record(): void
    {
        ['staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->office();

        $this->actingAsUser($staff);

        $this->getJson('/api/attendance')->assertOk()->assertJsonPath('units', []);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertStatus(403);

        $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $staff->org_unit_id,
            'rating_period_id' => $period->id,
            'title'            => 'A staff member cannot set this',
            'category'         => 'meeting',
            'held_on'          => $monday->toDateString(),
        ])->assertStatus(403);
    }

    public function test_a_locked_period_rejects_a_change(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->office();
        $period->update(['is_locked' => true]);

        $this->actingAsUser($hr);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertStatus(423);
    }

    public function test_the_rating_form_shows_the_recorded_attendance(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'year' => $year, 'period' => $period, 'monday' => $monday] = $this->office();

        $this->actingAsUser($hr);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertOk();

        $meeting = $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $staff->org_unit_id,
            'rating_period_id' => $period->id,
            'title'            => 'Committee',
            'category'         => 'meeting',
            'held_on'          => $monday->toDateString(),
        ])->assertCreated();

        $this->putJson("/api/attendance/meetings/{$meeting->json('meeting.id')}/marks", [
            'user_id' => $staff->id,
            'status'  => 'excused',
        ])->assertOk();

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $staff->org_unit_id,
            'user_id'        => $staff->id,
            'status'         => 'draft',
        ]);

        PcrOutput::create(['form_id' => $form->id, 'section' => 'support', 'title' => 'Submission of DTR']);
        PcrOutput::create(['form_id' => $form->id, 'section' => 'support', 'title' => 'Attendance to meetings']);
        PcrOutput::create(['form_id' => $form->id, 'section' => 'support', 'title' => 'Attendance to LGU Monday convocation']);
        PcrOutput::create(['form_id' => $form->id, 'section' => 'core', 'title' => 'Attendance to meetings']);

        $this->actingAsUser($staff);

        $shown = $this->getJson("/api/pcr-forms/{$form->id}")->assertOk();
        $category = function (string $title, string $section = 'support') use ($shown) {
            return collect($shown->json('outputs'))
                ->first(fn ($output) => $output['title'] === $title && $output['section'] === $section)['attendance_category'];
        };

        $this->assertSame('daily', $category('Submission of DTR'));
        $this->assertSame('meeting', $category('Attendance to meetings'));
        $this->assertSame('convocation', $category('Attendance to LGU Monday convocation'));
        $this->assertNull($category('Attendance to meetings', 'core'));
        $shown->assertJsonPath("attendance.{$period->id}.daily.present", 0);
        $shown->assertJsonPath("attendance.{$period->id}.daily.leave", 1);
        $shown->assertJsonPath("attendance.{$period->id}.daily.counted", 0);
        $shown->assertJsonPath("attendance.{$period->id}.daily.suggested", null);
        $shown->assertJsonPath("attendance.{$period->id}.meeting.counted", 0);
        $shown->assertJsonPath("attendance.{$period->id}.meeting.suggested", null);
    }

    public function test_a_full_attendance_record_suggests_five(): void
    {
        $this->assertSame(5, AttendanceRate::suggestedScore(100));
        $this->assertSame(5, AttendanceRate::suggestedScore(95));
        $this->assertSame(4, AttendanceRate::suggestedScore(94));
        $this->assertSame(4, AttendanceRate::suggestedScore(90));
        $this->assertSame(3, AttendanceRate::suggestedScore(89));
        $this->assertSame(3, AttendanceRate::suggestedScore(80));
        $this->assertSame(2, AttendanceRate::suggestedScore(79));
        $this->assertSame(2, AttendanceRate::suggestedScore(70));
        $this->assertSame(1, AttendanceRate::suggestedScore(69));
        $this->assertNull(AttendanceRate::suggestedScore(null));

        ['hr' => $hr, 'staff' => $staff, 'year' => $year, 'period' => $period, 'monday' => $monday] = $this->office();

        $this->actingAsUser($hr);
        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertOk();

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $staff->org_unit_id,
            'user_id'        => $staff->id,
            'status'         => 'draft',
        ]);
        PcrOutput::create(['form_id' => $form->id, 'section' => 'support', 'title' => 'Submission of DTR']);

        $this->actingAsUser($staff);
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath("attendance.{$period->id}.daily.pct", 100)
            ->assertJsonPath("attendance.{$period->id}.daily.suggested", 5);
    }

    public function test_hr_reads_another_office_without_recording_it(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->office();

        $dean = User::factory()->create(['role' => 'program_head', 'name' => 'College Dean']);
        $college = $this->makeUnit([
            'name'         => 'College of Teacher Education',
            'code'         => 'CTE',
            'type'         => 'college',
            'dean_user_id' => $dean->id,
        ]);
        $dean->update(['org_unit_id' => $college->id]);
        $faculty = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $college->id,
            'name'        => 'Faculty Member',
        ]);

        $this->actingAsUser($dean);
        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $faculty->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertOk();
        $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $college->id,
            'rating_period_id' => $period->id,
            'title'            => 'Faculty meeting',
            'category'         => 'meeting',
            'held_on'          => $monday->toDateString(),
        ])->assertCreated();

        $this->actingAsUser($hr);

        $sheet = $this->getJson("/api/attendance?rating_period_id={$period->id}&org_unit_id={$college->id}")
            ->assertOk()
            ->assertJsonPath('watching', true)
            ->assertJsonPath('readonly', true)
            ->assertJsonPath('selected_unit_id', $college->id);

        $this->assertContains($faculty->id, collect($sheet->json('members'))->pluck('id')->all());
        $this->assertNotContains($staff->id, collect($sheet->json('members'))->pluck('id')->all());
        $this->assertSame('present', $sheet->json('daily')[$faculty->id][$monday->toDateString()]);
        $sheet->assertJsonPath('meetings.0.title', 'Faculty meeting');

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $faculty->id,
            'day'              => $monday->toDateString(),
            'status'           => 'absent',
        ])->assertStatus(403);

        $own = $this->getJson("/api/attendance?rating_period_id={$period->id}&org_unit_id={$staff->org_unit_id}")
            ->assertOk()
            ->assertJsonPath('readonly', false);
        $this->assertContains($staff->id, collect($own->json('members'))->pluck('id')->all());
    }

    public function test_a_vp_records_heads_and_not_employees(): void
    {
        ['vp' => $vp, 'head' => $head, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->division();

        $this->actingAsUser($vp);

        $sheet = $this->getJson("/api/attendance?rating_period_id={$period->id}")->assertOk();
        $names = collect($sheet->json('members'))->pluck('id')->all();

        $this->assertContains($head->id, $names);
        $this->assertNotContains($staff->id, $names);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertStatus(403);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $head->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertOk();
    }

    private function office(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $monday  = Carbon::parse('2026-08-01')->next(Carbon::MONDAY);
        $period  = $this->makePeriod($year);
        $period->update([
            'opens_at'  => $monday->toDateString(),
            'closes_at' => $monday->copy()->addDays(6)->toDateString(),
        ]);

        $hr = User::factory()->create(['role' => 'hr']);
        $office = $this->makeUnit([
            'name'         => 'Human Resource Management Office',
            'code'         => 'AFS-HRMO',
            'type'         => 'office',
            'parent_id'    => $college->id,
            'head_user_id' => $hr->id,
        ]);
        $hr->update(['org_unit_id' => $office->id]);
        $staff = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $office->id,
            'name'        => 'HRMO Staff',
        ]);

        return compact('hr', 'staff', 'year', 'period', 'monday');
    }

    private function division(): array
    {
        $this->makeOrganization();

        $year   = $this->makeSchoolYear();
        $monday = Carbon::parse('2026-08-01')->next(Carbon::MONDAY);
        $period = $this->makePeriod($year);
        $period->update([
            'opens_at'  => $monday->toDateString(),
            'closes_at' => $monday->copy()->addDays(6)->toDateString(),
        ]);

        $vp = User::factory()->create(['role' => 'vp', 'name' => 'Academic VP']);
        $division = $this->makeUnit([
            'name'       => 'Academic Affairs',
            'code'       => 'AAS',
            'type'       => 'division',
            'vp_user_id' => $vp->id,
        ]);
        $vp->update(['org_unit_id' => $division->id]);

        $head = User::factory()->create(['role' => 'program_head', 'name' => 'College Dean']);
        $college = $this->makeUnit([
            'name'         => 'College of Teacher Education',
            'code'         => 'CTE',
            'type'         => 'college',
            'parent_id'    => $division->id,
            'dean_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $college->id]);

        $staff = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $college->id,
            'name'        => 'Faculty Member',
        ]);

        return compact('vp', 'head', 'staff', 'year', 'period', 'monday');
    }
}
