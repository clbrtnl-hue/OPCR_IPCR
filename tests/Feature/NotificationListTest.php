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
}
