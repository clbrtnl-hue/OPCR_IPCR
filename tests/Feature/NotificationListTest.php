<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Tests\PmsTestCase;

class NotificationListTest extends PmsTestCase
{
    public function test_a_body_is_stored_and_returned_as_plain_words(): void
    {
        $this->makeOrganization();

        $me    = User::factory()->create(['role' => 'employee']);
        $actor = User::factory()->create(['role' => 'program_head']);

        $this->actingAsUser($actor);

        Notification::send($me->id, 'assignment', 'A commitment was assigned to you', '<p>Publish <strong>25</strong> researches.</p>');

        $this->assertSame('Publish 25 researches.', Notification::where('user_id', $me->id)->value('body'));

        // A legacy row that still holds markup is cleaned on the way out.
        Notification::where('user_id', $me->id)->update(['body' => '<p>Old &amp; crusty.</p>']);

        $this->actingAsUser($me);

        $body = $this->getJson('/api/notifications')->assertSuccessful()->json('items.0.body');

        $this->assertSame('Old & crusty.', $body);
    }

    public function test_the_list_pages_filters_and_counts(): void
    {
        $this->makeOrganization();

        $me = $this->actingAsRole('employee');

        foreach (range(1, 8) as $index) {
            Notification::create([
                'user_id' => $me->id,
                'type'    => $index % 2 === 0 ? 'assignment' : 'rated',
                'title'   => "Notice {$index}",
                'read_at' => $index > 5 ? now() : null,
            ]);
        }

        $all = $this->getJson('/api/notifications?limit=3')->assertSuccessful()->json();

        $this->assertCount(3, $all['items']);
        $this->assertSame(8, $all['total']);
        $this->assertSame(5, $all['unread']);
        $this->assertSame(4, $all['kinds']['assignment']);

        $unread = $this->getJson('/api/notifications?unread=1&limit=50')->json();

        $this->assertCount(5, $unread['items']);

        $rated = $this->getJson('/api/notifications?type=rated&limit=50')->json();

        $this->assertCount(4, $rated['items']);
        $this->assertSame(4, $rated['total']);
    }

    public function test_trash_keeps_the_row_until_thirty_days_have_passed(): void
    {
        $this->makeOrganization();

        $me    = $this->actingAsRole('employee');
        $other = User::factory()->create(['role' => 'employee']);

        $keep = Notification::create([
            'user_id' => $me->id,
            'type'    => 'assignment',
            'title'   => 'Keep me',
        ]);
        $hide = Notification::create([
            'user_id' => $me->id,
            'type'    => 'rated',
            'title'   => 'Hide me',
        ]);
        $foreign = Notification::create([
            'user_id' => $other->id,
            'type'    => 'review',
            'title'   => 'Not yours',
        ]);

        $this->postJson("/api/notifications/{$hide->id}/restore")->assertNotFound();
        $this->postJson("/api/notifications/{$foreign->id}/trash")->assertNotFound();
        $this->postJson("/api/notifications/{$hide->id}/trash")->assertSuccessful();

        $inbox = $this->getJson('/api/notifications')->assertSuccessful()->json();

        $this->assertSame(['Keep me'], array_column($inbox['items'], 'title'));
        $this->assertSame(1, $inbox['unread']);
        $this->assertSame(1, $inbox['trash']);
        $this->assertSame(1, $inbox['kinds']['assignment']);
        $this->assertArrayNotHasKey('rated', $inbox['kinds']);
        $this->assertNotNull($hide->fresh()->trashed_at);

        $trashed = $this->getJson('/api/notifications?trash=1')->assertSuccessful()->json();

        $this->assertSame(['Hide me'], array_column($trashed['items'], 'title'));
        $this->assertSame(1, $trashed['unread']);

        $this->postJson('/api/notifications/read-all')->assertSuccessful();

        $this->assertNotNull($keep->fresh()->read_at);
        $this->assertNull($hide->fresh()->read_at);

        $this->postJson("/api/notifications/{$hide->id}/restore")->assertSuccessful();
        $this->assertNull($hide->fresh()->trashed_at);

        $this->postJson("/api/notifications/{$hide->id}/trash")->assertSuccessful();
        $hide->update(['trashed_at' => now()->subDays(30)]);
        $keep->update(['trashed_at' => now()->subDays(29)]);

        $this->artisan('pms:purge-notifications')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $hide->id]);
        $this->assertNotNull($keep->fresh());
        $this->assertNotNull($foreign->fresh());
    }

    public function test_delete_all_moves_only_the_inbox_into_trash(): void
    {
        $this->makeOrganization();

        $me    = $this->actingAsRole('employee');
        $other = User::factory()->create(['role' => 'employee']);

        $first = Notification::create([
            'user_id' => $me->id,
            'type'    => 'assignment',
            'title'   => 'First',
        ]);
        $second = Notification::create([
            'user_id' => $me->id,
            'type'    => 'rated',
            'title'   => 'Second',
            'read_at' => now(),
        ]);
        $kept = now()->subDay()->startOfSecond();
        $already = Notification::create([
            'user_id'    => $me->id,
            'type'       => 'review',
            'title'      => 'Already gone',
            'trashed_at' => $kept,
        ]);
        $foreign = Notification::create([
            'user_id' => $other->id,
            'type'    => 'review',
            'title'   => 'Not yours',
        ]);

        $this->postJson('/api/notifications/trash-all')->assertSuccessful();

        $this->assertNotNull($first->fresh()->trashed_at);
        $this->assertNotNull($second->fresh()->trashed_at);
        $this->assertSame($kept->toDateTimeString(), $already->fresh()->trashed_at->toDateTimeString());
        $this->assertNull($foreign->fresh()->trashed_at);

        $inbox = $this->getJson('/api/notifications')->assertSuccessful()->json();

        $this->assertSame(0, $inbox['inbox']);
        $this->assertSame(0, $inbox['total']);
        $this->assertSame(3, $inbox['trash']);
    }
}
