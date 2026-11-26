<?php

namespace Tests\Feature;

use App\Models\AttendanceCategory;
use App\Models\AttendanceDay;
use App\Models\AttendanceMeeting;
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

        $sheet = $this->getJson('/api/attendance')->assertOk()->assertJsonPath('readonly', true);
        $this->assertEquals([$staff->id], collect($sheet->json('members'))->pluck('id')->all());

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

    public function test_a_leave_day_is_not_overwritten_by_a_meeting_mark(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->office();
        $day = $monday->toDateString();

        $this->actingAsUser($hr);

        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $day,
            'status'           => 'leave',
        ])->assertOk();

        $meeting = $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $staff->org_unit_id,
            'rating_period_id' => $period->id,
            'title'            => 'Emergency meeting',
            'category'         => 'meeting',
            'held_on'          => $day,
        ])->assertCreated();

        $dayStatus = fn () => AttendanceDay::query()
            ->where('user_id', $staff->id)
            ->whereDate('day', $day)
            ->value('status');

        $this->putJson("/api/attendance/meetings/{$meeting->json('meeting.id')}/marks", [
            'user_id' => $staff->id,
            'status'  => 'present',
        ])->assertOk();

        $this->assertSame('leave', $dayStatus());
        $this->assertDatabaseHas('attendance_rates', [
            'user_id'          => $staff->id,
            'rating_period_id' => $period->id,
            'scope'            => 'daily',
            'present'          => 0,
            'absent'           => 0,
            'excused'          => 1,
            'counted'          => 0,
            'pct'              => null,
        ]);
        $this->assertDatabaseHas('attendance_rates', [
            'user_id'          => $staff->id,
            'rating_period_id' => $period->id,
            'scope'            => 'meeting',
            'present'          => 1,
            'pct'              => 100,
        ]);

        $this->putJson("/api/attendance/meetings/{$meeting->json('meeting.id')}/marks", [
            'user_id' => $staff->id,
            'status'  => 'absent',
        ])->assertOk();

        $this->assertSame('leave', $dayStatus());

        $this->putJson("/api/attendance/meetings/{$meeting->json('meeting.id')}/marks", [
            'user_id' => $staff->id,
            'status'  => null,
        ])->assertOk();

        $this->assertSame('leave', $dayStatus());
    }

    public function test_a_head_can_mark_everyone_present_on_a_meeting(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->office();

        $other = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $staff->org_unit_id,
            'name'        => 'Second Staff',
        ]);

        $this->actingAsUser($hr);

        $meeting = $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $staff->org_unit_id,
            'rating_period_id' => $period->id,
            'title'            => 'Faculty meeting',
            'category'         => 'meeting',
            'held_on'          => $monday->toDateString(),
        ])->assertCreated();

        $this->putJson("/api/attendance/meetings/{$meeting->json('meeting.id')}/marks/all")
            ->assertOk()
            ->assertJsonPath('marked', 2)
            ->assertJsonPath('rate.present', 2)
            ->assertJsonPath('rate.pct', 100);

        $this->assertDatabaseHas('attendance_meeting_marks', [
            'meeting_id' => $meeting->json('meeting.id'),
            'user_id'    => $staff->id,
            'status'     => 'present',
        ]);
        $this->assertDatabaseHas('attendance_meeting_marks', [
            'meeting_id' => $meeting->json('meeting.id'),
            'user_id'    => $other->id,
            'status'     => 'present',
        ]);
        $this->assertDatabaseHas('attendance_rates', [
            'user_id'          => $staff->id,
            'rating_period_id' => $period->id,
            'scope'            => 'meeting',
            'pct'              => 100,
        ]);
    }

    public function test_support_titles_map_to_attendance_categories(): void
    {
        $this->assertSame('daily', AttendanceMeeting::categoryForTitle('Daily Attendance / DTR'));
        $this->assertSame('daily', AttendanceMeeting::categoryForTitle('Submission of Daily Time Record'));
        $this->assertSame('convocation', AttendanceMeeting::categoryForTitle('Flag Raising'));
        $this->assertSame('convocation', AttendanceMeeting::categoryForTitle('Monday Flag Ceremony'));
        $this->assertSame('training', AttendanceMeeting::categoryForTitle('Seminar / Workshop'));
        $this->assertSame('training', AttendanceMeeting::categoryForTitle('Attendance to School Meetings and Trainings'));
        $this->assertSame('lgu', AttendanceMeeting::categoryForTitle('LGU Outreach'));
        $this->assertSame('meeting', AttendanceMeeting::categoryForTitle('Attendance to meetings'));
        $this->assertSame('daily', AttendanceMeeting::categoryForTitle('Submission of Bundy Card'));
        $this->assertSame('daily', AttendanceMeeting::categoryForTitle('Timekeeping'));
        $this->assertSame('convocation', AttendanceMeeting::categoryForTitle('Flag retreat'));
        $this->assertSame('training', AttendanceMeeting::categoryForTitle('Webinar on research'));
        $this->assertSame('training', AttendanceMeeting::categoryForTitle('Capability-building activity'));
        $this->assertSame('lgu', AttendanceMeeting::categoryForTitle('Barangay activities'));
        $this->assertSame('lgu', AttendanceMeeting::categoryForTitle('Attendance to local government activities'));
        $this->assertSame('school', AttendanceMeeting::categoryForTitle('Campus activities'));
        $this->assertSame('school', AttendanceMeeting::categoryForTitle('Foundation day'));
        $this->assertSame('meeting', AttendanceMeeting::categoryForTitle('EXECOM'));
        $this->assertNull(AttendanceMeeting::categoryForTitle('Maintenance of Classroom / Committee Chairmanship'));
        $this->assertNull(AttendanceMeeting::categoryForTitle(''));
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
            ->assertJsonPath('watching', false)
            ->assertJsonPath('readonly', false);

        $this->assertNotContains($faculty->id, collect($sheet->json('members'))->pluck('id')->all());
        $this->assertContains($staff->id, collect($sheet->json('members'))->pluck('id')->all());

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

    public function test_a_vp_and_the_president_have_no_attendance_on_their_account(): void
    {
        ['vp' => $vp, 'head' => $head, 'staff' => $staff, 'period' => $period, 'monday' => $monday] = $this->division();

        $president = User::factory()->create(['role' => 'president', 'name' => 'College President']);
        $this->makeUnit([
            'name'         => 'Opol Community College',
            'code'         => 'OCC',
            'type'         => 'college',
            'head_user_id' => $president->id,
        ]);

        foreach ([$vp, $president] as $account) {
            $this->actingAsUser($account);

            $this->getJson('/api/attendance')->assertStatus(403);

            $this->putJson('/api/attendance/days', [
                'rating_period_id' => $period->id,
                'user_id'          => $head->id,
                'day'              => $monday->toDateString(),
                'status'           => 'present',
            ])->assertStatus(403);

            $this->postJson('/api/attendance/meetings', [
                'org_unit_id'      => $head->org_unit_id,
                'rating_period_id' => $period->id,
                'title'            => 'Not their page',
                'category'         => 'meeting',
                'held_on'          => $monday->toDateString(),
            ])->assertStatus(403);
        }

        $this->actingAsUser($head);

        $sheet = $this->getJson("/api/attendance?rating_period_id={$period->id}")->assertOk();
        $this->assertContains($staff->id, collect($sheet->json('members'))->pluck('id')->all());
    }

    public function test_a_head_dashboard_reads_attendance_averages(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'year' => $year, 'period' => $period, 'monday' => $monday] = $this->office();

        $this->actingAsUser($hr);
        $this->putJson('/api/attendance/days', [
            'rating_period_id' => $period->id,
            'user_id'          => $staff->id,
            'day'              => $monday->toDateString(),
            'status'           => 'present',
        ])->assertOk();

        $this->getJson('/api/attendance?summary=1&school_year_id='.$year->id.'&rating_period_id='.$period->id)
            ->assertOk()
            ->assertJsonPath('daily_pct', 100)
            ->assertJsonPath('rows.0.name', 'HRMO Staff')
            ->assertJsonPath('rows.0.daily_pct', 100);

        $this->actingAsUser($staff);
        $this->getJson('/api/attendance?summary=1&school_year_id='.$year->id.'&rating_period_id='.$period->id)
            ->assertOk()
            ->assertJsonPath('rows', []);
    }

    public function test_the_head_links_support_evidence_while_rating(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'year' => $year, 'period' => $period, 'monday' => $monday] = $this->office();

        $this->actingAsUser($hr);
        $meeting = $this->postJson('/api/attendance/meetings', [
            'org_unit_id'      => $staff->org_unit_id,
            'rating_period_id' => $period->id,
            'title'            => 'Committee',
            'category'         => 'meeting',
            'held_on'          => $monday->toDateString(),
        ])->assertCreated();
        $this->putJson("/api/attendance/meetings/{$meeting->json('meeting.id')}/marks", [
            'user_id' => $staff->id,
            'status'  => 'present',
        ])->assertOk();

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $staff->org_unit_id,
            'user_id'        => $staff->id,
            'status'         => 'draft',
        ]);
        $output = PcrOutput::create([
            'form_id' => $form->id,
            'section' => 'support',
            'title'   => 'Accreditation documents',
        ]);
        $category = AttendanceCategory::ensure()->firstWhere('slug', 'meeting');

        $payload = [
            'id'                     => $output->id,
            'form_id'                => $form->id,
            'section'                => 'support',
            'attendance_category_id' => $category->id,
        ];

        $this->actingAsUser($staff);
        $this->postJson('/api/pcr-outputs', $payload)->assertStatus(403);
        $this->assertNull($output->fresh()->attendance_category_id);

        $form->forceFill(['status' => 'head_review', 'head_reviewer_id' => $hr->id])->save();

        $this->actingAsUser($hr);
        // Nothing is linked yet, so the head is offered the pick.
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('can_link_attendance', true)
            ->assertJsonPath('outputs.0.attendance_category', null);
        $this->postJson('/api/pcr-outputs', $payload)->assertOk();
        $this->assertSame($category->id, (int) $output->fresh()->attendance_category_id);
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('outputs.0.attendance_category', 'meeting');

        $this->actingAsUser($staff);
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('can_link_attendance', false);

        $qa = User::factory()->create(['role' => 'qa']);
        $form->forceFill(['status' => 'qa_rating'])->save();
        $this->actingAsUser($qa);
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('can_link_attendance', false)
            ->assertJsonPath('outputs.0.attendance_category', 'meeting')
            ->assertJsonPath("attendance.{$period->id}.meeting.pct", 100)
            ->assertJsonPath("attendance.{$period->id}.meeting.suggested", 5);

        $vp = User::factory()->create(['role' => 'vp', 'name' => 'Academic VP']);
        $form->forceFill(['status' => 'vp_review', 'vp_reviewer_id' => $vp->id])->save();
        $this->actingAsUser($vp);
        $this->getJson("/api/pcr-forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath("attendance.{$period->id}.meeting.pct", 100);

        $this->actingAsUser($hr);
        $this->postJson('/api/pcr-outputs', $payload)->assertStatus(403);
    }

    public function test_the_dean_links_evidence_at_their_own_review_stage(): void
    {
        ['hr' => $hr, 'staff' => $staff, 'year' => $year, 'period' => $period, 'monday' => $monday] = $this->office();

        $dean = User::factory()->create(['role' => 'program_head', 'name' => 'College Dean']);

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $staff->org_unit_id,
            'user_id'        => $staff->id,
            'status'         => 'dean_review',
        ]);
        $form->forceFill(['dean_reviewer_id' => $dean->id])->save();

        $output = PcrOutput::create([
            'form_id' => $form->id,
            'section' => 'support',
            'title'   => 'Accreditation documents',
        ]);
        $category = AttendanceCategory::ensure()->firstWhere('slug', 'meeting');
        $payload = [
            'id'                     => $output->id,
            'form_id'                => $form->id,
            'section'                => 'support',
            'attendance_category_id' => $category->id,
        ];

        $this->actingAsUser($hr);
        $this->postJson('/api/pcr-outputs', $payload)->assertStatus(403);

        $this->actingAsUser($dean);
        $this->postJson('/api/pcr-outputs', $payload)->assertOk();
        $this->assertSame($category->id, (int) $output->fresh()->attendance_category_id);
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

        $hr = User::factory()->create(['role' => 'program_head']);
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
