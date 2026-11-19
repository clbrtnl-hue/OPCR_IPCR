<?php

namespace Tests\Feature;

use App\Mail\PmsNotificationMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;

class NotificationMailTest extends PmsTestCase
{
    public function test_a_notice_is_mailed_with_the_same_words_as_the_bell(): void
    {
        $this->makeOrganization();
        Mail::fake();

        $me    = User::factory()->create(['role' => 'employee']);
        $actor = User::factory()->create(['role' => 'program_head']);

        $this->actingAsUser($actor);

        Notification::send(
            $me->id,
            'assignment',
            'A commitment was assigned to you',
            '<p>Publish <strong>25</strong> researches.</p>',
            null,
            '/forms/9',
        );

        Mail::assertSent(PmsNotificationMail::class, function (PmsNotificationMail $mail) use ($me, $actor) {
            $html = $mail->render();

            $text = view('mail.pms-notification-text', [
                'headline'  => $mail->headline,
                'detail'    => $mail->detail,
                'actionUrl' => $mail->actionUrl,
                'actorName' => $mail->actorName,
            ])->render();

            return $mail->hasTo($me->email)
                && $mail->headline === 'A commitment was assigned to you'
                && $mail->detail === 'Publish 25 researches.'
                && $mail->actorName === $actor->name
                && str_ends_with($mail->actionUrl, '/forms/9')
                && str_contains($html, 'Publish 25 researches.')
                && str_contains($html, 'From '.$actor->name)
                && str_contains($html, '/forms/9')
                && str_contains($text, 'Open in PMS:');
        });

        Mail::assertNotSent(PmsNotificationMail::class, function (PmsNotificationMail $mail) use ($actor) {
            return $mail->hasTo($actor->email);
        });
    }

    public function test_the_actor_is_not_mailed_about_their_own_action(): void
    {
        $this->makeOrganization();
        Mail::fake();

        $actor = User::factory()->create(['role' => 'program_head']);
        $this->actingAsUser($actor);

        Notification::send($actor->id, 'review', 'Sent for review');

        Mail::assertNothingSent();
        $this->assertSame(0, Notification::count());
    }

    public function test_a_reminder_is_mailed_once(): void
    {
        $this->makeOrganization();
        Mail::fake();

        $owner = User::factory()->create(['role' => 'employee']);
        $link  = '/forms/4?line=8&when=soon';

        $this->assertTrue(Notification::remind(
            $owner->id,
            'due_soon',
            'A commitment is due soon',
            'Publish the research.',
            null,
            $link,
        ));

        $this->assertFalse(Notification::remind(
            $owner->id,
            'due_soon',
            'A commitment is due soon',
            'Publish the research.',
            null,
            $link,
        ));

        Mail::assertSent(PmsNotificationMail::class, 1);
        Mail::assertSent(PmsNotificationMail::class, function (PmsNotificationMail $mail) use ($owner, $link) {
            return $mail->hasTo($owner->email)
                && $mail->actorName === null
                && str_contains($mail->actionUrl, $link);
        });

        Notification::query()->update(['trashed_at' => now()]);

        $this->assertFalse(Notification::remind(
            $owner->id,
            'due_soon',
            'A commitment is due soon',
            'Publish the research.',
            null,
            $link,
        ));
        Mail::assertSent(PmsNotificationMail::class, 1);

        Notification::query()->update(['trashed_at' => now()->subDays(30)]);
        $this->assertSame(1, Notification::purgeTrash());

        $this->assertTrue(Notification::remind(
            $owner->id,
            'due_soon',
            'A commitment is due soon',
            'Publish the research.',
            null,
            $link,
        ));
        Mail::assertSent(PmsNotificationMail::class, 2);
    }

    public function test_a_bad_address_still_leaves_the_bell(): void
    {
        $this->makeOrganization();
        Mail::fake();

        $me    = User::factory()->create(['role' => 'employee', 'email' => 'not-an-email']);
        $actor = User::factory()->create(['role' => 'qa']);
        $this->actingAsUser($actor);

        Notification::send($me->id, 'returned', 'Returned for correction', 'Attach the letters.');

        Mail::assertNothingSent();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $me->id,
            'title'   => 'Returned for correction',
        ]);
    }

    public function test_a_mail_failure_does_not_drop_the_bell(): void
    {
        $this->makeOrganization();

        $me    = User::factory()->create(['role' => 'employee']);
        $actor = User::factory()->create(['role' => 'qa']);
        $this->actingAsUser($actor);

        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        Notification::send($me->id, 'review', 'Ready for your review');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $me->id,
            'title'   => 'Ready for your review',
        ]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'smtp down')
        );
    }
}
