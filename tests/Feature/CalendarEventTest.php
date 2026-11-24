<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\OrgUnitReviewer;
use App\Models\User;
use Tests\PmsTestCase;

class CalendarEventTest extends PmsTestCase
{
    public function test_visibility_decides_who_sees_an_event(): void
    {
        $this->makeOrganization();
        $office = $this->makeUnit(['name' => 'College of Technology', 'code' => 'COT2']);
        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG2']);
        $owner = User::factory()->create(['role' => 'program_head', 'name' => 'Office Head', 'org_unit_id' => $office->id]);
        $guest = User::factory()->create(['role' => 'employee', 'name' => 'Invited Staff', 'org_unit_id' => $office->id]);
        $other = User::factory()->create(['role' => 'employee', 'name' => 'Someone Else', 'org_unit_id' => $elsewhere->id]);

        OrgUnitReviewer::create([
            'org_unit_id' => $office->id,
            'slot' => 'director_review',
            'user_id' => $owner->id,
        ]);

        $this->actingAsUser($owner);

        $private = $this->postJson('/api/calendar-events', [
            'title'      => 'My notes',
            'starts_at'  => '2026-11-24',
            'ends_at'    => '2026-11-24',
            'all_day'    => true,
            'visibility' => 'only_me',
        ])->assertCreated();

        $shared = $this->postJson('/api/calendar-events', [
            'title'      => 'Department meeting',
            'starts_at'  => '2026-11-24 09:00:00',
            'ends_at'    => '2026-11-24 10:00:00',
            'all_day'    => false,
            'visibility' => 'selected',
            'user_ids'   => [$guest->id],
        ])->assertCreated();

        $public = $this->postJson('/api/calendar-events', [
            'title'      => 'Foundation day',
            'starts_at'  => '2026-11-25',
            'ends_at'    => '2026-11-26',
            'all_day'    => true,
            'visibility' => 'everyone',
        ])->assertCreated();

        $this->postJson('/api/calendar-events', [
            'title'      => 'Backwards',
            'starts_at'  => '2026-11-26',
            'ends_at'    => '2026-11-24',
            'all_day'    => true,
            'visibility' => 'only_me',
        ])->assertStatus(422);

        $this->postJson('/api/calendar-events', [
            'title'      => 'Nobody',
            'starts_at'  => '2026-11-24',
            'ends_at'    => '2026-11-24',
            'all_day'    => true,
            'visibility' => 'selected',
        ])->assertStatus(422);

        $this->assertDatabaseHas('calendar_event_users', [
            'event_id' => $shared->json('event.id'),
            'user_id'  => $guest->id,
        ]);

        $mine = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json());
        $this->assertEqualsCanonicalizing(
            ['My notes', 'Department meeting', 'Foundation day'],
            $mine->pluck('title')->all()
        );

        $this->actingAsUser($guest);
        $seen = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json());
        $this->assertEqualsCanonicalizing(
            ['Department meeting', 'Foundation day'],
            $seen->pluck('title')->all()
        );

        $this->actingAsUser($other);
        $seen = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json());
        $this->assertSame([], $seen->pluck('title')->all());

        $this->actingAsUser($guest);
        $this->patchJson("/api/calendar-events/{$shared->json('event.id')}", [
            'title'      => 'Hijack',
            'starts_at'  => '2026-11-24 09:00:00',
            'ends_at'    => '2026-11-24 10:00:00',
            'all_day'    => false,
            'visibility' => 'everyone',
        ])->assertStatus(403);

        $this->actingAsUser($owner);
        $this->patchJson("/api/calendar-events/{$private->json('event.id')}", [
            'title'      => 'My notes revised',
            'starts_at'  => '2026-11-24',
            'ends_at'    => '2026-11-24',
            'all_day'    => true,
            'visibility' => 'only_me',
        ])->assertOk()->assertJsonPath('event.title', 'My notes revised');

        $this->deleteJson("/api/calendar-events/{$public->json('event.id')}")->assertOk();

        $this->actingAsUser($other);
        $seen = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json());
        $this->assertSame([], $seen->pluck('title')->all());
    }

    public function test_an_event_stays_inside_its_organization(): void
    {
        $this->makeOrganization(['name' => 'Opol Community College', 'code' => 'occ']);
        $office = $this->makeUnit();
        $owner = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $office->id]);
        OrgUnitReviewer::create([
            'org_unit_id' => $office->id,
            'slot' => 'director_review',
            'user_id' => $owner->id,
        ]);

        $this->actingAsUser($owner);
        $this->postJson('/api/calendar-events', [
            'title'      => 'Campus assembly',
            'starts_at'  => '2026-11-24',
            'ends_at'    => '2026-11-24',
            'all_day'    => true,
            'visibility' => 'everyone',
        ])->assertCreated();

        $this->makeOrganization(['name' => 'Other College', 'code' => 'oth']);
        $outsider = User::factory()->create(['role' => 'employee']);

        $this->actingAsUser($outsider);
        $seen = $this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json();

        $this->assertSame([], $seen);
    }

    public function test_a_head_shares_a_selected_event_only_with_people_they_lead(): void
    {
        $this->makeOrganization();
        $office = $this->makeUnit(['name' => 'College of Technology', 'code' => 'COT']);
        $child = $this->makeUnit([
            'name' => 'BS Information Systems',
            'code' => 'BSIS',
            'parent_id' => $office->id,
        ]);
        $elsewhere = $this->makeUnit(['name' => 'Registrar', 'code' => 'REG']);
        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $office->id]);
        $staff = User::factory()->create(['role' => 'employee', 'name' => 'Faculty Member', 'org_unit_id' => $child->id]);
        $outsider = User::factory()->create(['role' => 'employee', 'name' => 'Other Office', 'org_unit_id' => $elsewhere->id]);

        OrgUnitReviewer::create([
            'org_unit_id' => $office->id,
            'slot' => 'director_review',
            'user_id' => $head->id,
        ]);

        $this->actingAsUser($head);

        $options = collect($this->getJson('/api/users/options?scope=led')->assertOk()->json());
        $this->assertEqualsCanonicalizing([$staff->id], $options->pluck('id')->all());

        $this->postJson('/api/calendar-events', [
            'title' => 'Faculty meeting',
            'starts_at' => '2026-11-24',
            'ends_at' => '2026-11-24',
            'all_day' => true,
            'visibility' => 'selected',
            'user_ids' => [$staff->id],
        ])->assertCreated();

        $this->postJson('/api/calendar-events', [
            'title' => 'Wrong office',
            'starts_at' => '2026-11-24',
            'ends_at' => '2026-11-24',
            'all_day' => true,
            'visibility' => 'selected',
            'user_ids' => [$outsider->id],
        ])->assertStatus(422);
    }

    public function test_the_president_and_an_administrator_share_the_college_without_attendance(): void
    {
        $this->makeOrganization();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Registrar Admin']);
        $president = User::factory()->create(['role' => 'president', 'name' => 'College President']);
        $vp = User::factory()->create(['role' => 'vp', 'name' => 'Academic VP']);
        $head = User::factory()->create(['role' => 'program_head', 'name' => 'Dean']);
        $staff = User::factory()->create(['role' => 'employee', 'name' => 'Faculty']);

        $this->actingAsUser($admin);

        $byRole = $this->postJson('/api/calendar-events', [
            'title' => 'Foundation day',
            'starts_at' => '2026-11-25',
            'ends_at' => '2026-11-25',
            'all_day' => true,
            'visibility' => 'role',
            'audience_role' => 'employee',
            'category_id' => 1,
        ])->assertCreated();

        $this->assertNull($byRole->json('event.category_id'));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $staff->id,
            'type' => 'calendar',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $head->id,
            'type' => 'calendar',
        ]);

        $this->postJson('/api/calendar-events', [
            'title' => 'Call the president',
            'starts_at' => '2026-11-26',
            'ends_at' => '2026-11-26',
            'all_day' => true,
            'visibility' => 'selected',
            'user_ids' => [$president->id, $vp->id],
        ])->assertCreated();

        $college = $this->postJson('/api/calendar-events', [
            'title' => 'College assembly',
            'starts_at' => '2026-11-27',
            'ends_at' => '2026-11-27',
            'all_day' => true,
            'visibility' => 'everyone',
        ])->assertCreated();

        $this->putJson('/api/calendar-events/' . $byRole->json('event.id') . '/marks', [
            'user_id' => $staff->id,
            'day' => '2026-11-25',
            'status' => 'present',
        ])->assertStatus(403);

        $this->actingAsUser($staff);
        $seen = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json())->pluck('title');
        $this->assertTrue($seen->contains('Foundation day'));
        $this->assertTrue($seen->contains('College assembly'));
        $this->assertFalse($seen->contains('Call the president'));

        $this->actingAsUser($president);
        $seen = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json())->pluck('title');
        $this->assertTrue($seen->contains('Call the president'));
        $this->assertTrue($seen->contains('College assembly'));
        $this->assertFalse($seen->contains('Foundation day'));

        $this->actingAsUser($vp);
        $this->postJson('/api/calendar-events', [
            'title' => 'Not by role',
            'starts_at' => '2026-11-28',
            'ends_at' => '2026-11-28',
            'all_day' => true,
            'visibility' => 'role',
            'audience_role' => 'employee',
        ])->assertStatus(422);

        $this->assertGreaterThan(0, Notification::query()->where('user_id', $president->id)->count());
        $this->assertSame($college->json('event.visibility'), 'everyone');
    }

    public function test_an_employee_keeps_a_private_event(): void
    {
        $this->makeOrganization();
        $staff = User::factory()->create(['role' => 'employee', 'name' => 'Faculty']);
        $other = User::factory()->create(['role' => 'employee', 'name' => 'Someone Else']);

        $this->actingAsUser($staff);

        $this->postJson('/api/calendar-events', [
            'title' => 'My reminder',
            'starts_at' => '2026-11-24',
            'ends_at' => '2026-11-24',
            'all_day' => true,
            'visibility' => 'only_me',
        ])->assertCreated();

        $this->postJson('/api/calendar-events', [
            'title' => 'Shared by mistake',
            'starts_at' => '2026-11-24',
            'ends_at' => '2026-11-24',
            'all_day' => true,
            'visibility' => 'selected',
            'user_ids' => [$other->id],
        ])->assertStatus(422);

        $this->actingAsUser($other);
        $titles = collect($this->getJson('/api/calendar-events?from=2026-11-01&to=2026-11-30')->assertOk()->json())->pluck('title');
        $this->assertFalse($titles->contains('My reminder'));
    }
}
