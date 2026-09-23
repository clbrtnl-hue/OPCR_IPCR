<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\PmsTestCase;

class PasswordChangeTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeOrganization();
        RateLimiter::clear('login');
    }

    public function test_a_person_changes_their_own_password(): void
    {
        $user = $this->actingAsRole('employee');

        $this->postJson('/api/my-password', [
            'current_password'      => 'password',
            'password'              => 'a-longer-secret',
            'password_confirmation' => 'a-longer-secret',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-longer-secret', $user->fresh()->password));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => 'User',
            'subject_id'   => $user->id,
            'action'       => 'password',
        ]);
    }

    public function test_the_current_password_must_be_right(): void
    {
        $user = $this->actingAsRole('employee');

        $this->postJson('/api/my-password', [
            'current_password'      => 'not-my-password',
            'password'              => 'a-longer-secret',
            'password_confirmation' => 'a-longer-secret',
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'That is not your current password.');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_new_password_is_confirmed_long_enough_and_actually_new(): void
    {
        $this->actingAsRole('employee');

        $this->postJson('/api/my-password', [
            'current_password'      => 'password',
            'password'              => 'a-longer-secret',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/my-password', [
            'current_password'      => 'password',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/my-password', [
            'current_password'      => 'password',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_stranger_cannot_change_a_password(): void
    {
        $this->postJson('/api/my-password', [
            'current_password'      => 'password',
            'password'              => 'a-longer-secret',
            'password_confirmation' => 'a-longer-secret',
        ])->assertStatus(401);
    }

    public function test_the_old_password_stops_signing_in(): void
    {
        $user = $this->actingAsRole('employee', ['email' => 'faculty@occ.edu.ph']);

        $this->postJson('/api/my-password', [
            'current_password'      => 'password',
            'password'              => 'a-longer-secret',
            'password_confirmation' => 'a-longer-secret',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'faculty@occ.edu.ph', 'password' => 'password',
        ])->assertStatus(401);

        $this->assertTrue(Hash::check('a-longer-secret', $user->fresh()->password));
    }

    public function test_a_refused_sign_in_says_why(): void
    {
        $user = User::factory()->create(['email' => 'faculty@occ.edu.ph']);

        $this->postJson('/api/login', ['email' => 'faculty@occ.edu.ph', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Those credentials do not match our records.');

        $this->postJson('/api/login', ['email' => 'nobody@occ.edu.ph', 'password' => 'password'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Those credentials do not match our records.');

        $user->update(['status' => 'inactive']);

        $this->postJson('/api/login', ['email' => 'faculty@occ.edu.ph', 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account is inactive. Ask an administrator to reactivate it.');

        $this->postJson('/api/login', ['email' => 'not-an-address', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_repeated_sign_in_attempts_are_throttled(): void
    {
        User::factory()->create(['email' => 'target@occ.edu.ph']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', [
                'email' => 'target@occ.edu.ph', 'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'email' => 'target@occ.edu.ph', 'password' => 'wrong',
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many sign-in attempts. Wait a minute and try again.');

        $this->postJson('/api/login', [
            'email' => 'somebody.else@occ.edu.ph', 'password' => 'wrong',
        ])->assertStatus(401);
    }
}
