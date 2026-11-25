<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PcrStatusLog;
use App\Models\User;
use App\Services\InactiveAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Token;
use Tests\PmsTestCase;

class InactiveAccountTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year);

        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $vp = User::factory()->create(['role' => 'vp', 'name' => 'Vera VP']);
        $head = User::factory()->create(['role' => 'program_head', 'name' => 'Hugo Head']);
        $qa = User::factory()->create(['role' => 'qa', 'name' => 'Qara QA']);

        $office = $this->makeUnit([
            'name'         => 'Research and Extension Office',
            'code'         => 'RES',
            'parent_id'    => $college->id,
            'head_user_id' => $head->id,
            'vp_user_id'   => $vp->id,
        ]);

        $head->update(['org_unit_id' => $office->id]);
        $faculty = User::factory()->create([
            'role'        => 'employee',
            'org_unit_id' => $office->id,
            'name'        => 'Rita Faculty',
        ]);

        return compact('college', 'office', 'year', 'period', 'admin', 'vp', 'head', 'qa', 'faculty');
    }

    public function test_deactivating_ends_the_open_session(): void
    {
        ['admin' => $admin, 'vp' => $vp] = $this->college();
        $bearer = $this->bearerFor($vp);

        $this->withToken($bearer)->getJson('/api/me')->assertStatus(401);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/users/{$vp->id}")->assertOk();

        $this->assertTrue((bool) Token::query()->where('user_id', $vp->id)->value('revoked'));

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->getJson('/api/me')
            ->assertStatus(403)
            ->assertJsonPath('message', InactiveAccount::MESSAGE);
    }

    public function test_deactivating_moves_a_form_that_was_waiting_on_them(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'admin' => $admin, 'vp' => $vp, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $form = $this->makeForm([
            'org_unit_id'      => $office->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $faculty->id,
            'status'           => 'head_review',
            'head_reviewer_id' => $head->id,
        ]);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/users/{$head->id}")->assertOk();

        $fresh = $form->fresh();
        $this->assertSame('vp_review', $fresh->status);
        $this->assertSame($vp->id, (int) $fresh->vp_reviewer_id);
        $this->assertSame($head->id, (int) $office->fresh()->head_user_id);
        $this->assertDatabaseHas('pcr_status_logs', [
            'form_id'     => $form->id,
            'from_status' => 'head_review',
            'to_status'   => 'vp_review',
            'note'        => 'Moved on because the reviewer account is inactive.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vp->id,
            'form_id' => $form->id,
            'title'   => 'IPCR from Rita Faculty needs your review',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $faculty->id,
            'form_id' => $form->id,
            'title'   => 'Your IPCR moved on',
        ]);
    }

    public function test_setting_the_account_inactive_reassigns_commitment_approval(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'admin' => $admin, 'vp' => $vp, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $form = $this->makeForm([
            'org_unit_id'            => $office->id,
            'school_year_id'         => $year->id,
            'rating_period_id'       => $period->id,
            'user_id'                => $faculty->id,
            'status'                 => 'commitment_review',
            'commitment_approver_id' => $head->id,
        ]);

        $this->actingAsUser($admin);
        $this->postJson('/api/users', [
            'id'         => $head->id,
            'first_name' => 'Hugo',
            'last_name'  => 'Head',
            'email'      => $head->email,
            'role'       => 'program_head',
            'status'     => 'inactive',
        ])->assertOk();

        $this->assertSame($vp->id, (int) $form->fresh()->commitment_approver_id);
        $this->assertSame('commitment_review', $form->fresh()->status);
        $this->assertDatabaseHas('pcr_status_logs', [
            'form_id' => $form->id,
            'note'    => InactiveAccount::MOVED_NOTE,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vp->id,
            'form_id' => $form->id,
            'title'   => 'IPCR from Rita Faculty needs your review',
        ]);
    }

    public function test_a_commitment_with_nobody_left_is_logged_once(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'admin' => $admin, 'vp' => $vp, 'head' => $head, 'faculty' => $faculty, 'qa' => $qa] = $this->college();

        $vp->update(['status' => 'inactive']);
        $qa->update(['status' => 'inactive']);

        $form = $this->makeForm([
            'org_unit_id'            => $office->id,
            'school_year_id'         => $year->id,
            'rating_period_id'       => $period->id,
            'user_id'                => $faculty->id,
            'status'                 => 'commitment_review',
            'commitment_approver_id' => $head->id,
        ]);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/users/{$head->id}")->assertOk();
        app(InactiveAccount::class)->sweep();

        $fresh = $form->fresh();
        $this->assertSame('returned', $fresh->status);
        $this->assertNull($fresh->commitment_approver_id);
        $this->assertSame(1, PcrStatusLog::query()->where('form_id', $form->id)->where('to_status', 'returned')->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $faculty->id,
            'form_id' => $form->id,
            'title'   => 'Your IPCR came back to you',
        ]);

        app(InactiveAccount::class)->sweep();
        $this->assertSame(1, PcrStatusLog::query()->where('form_id', $form->id)->where('to_status', 'returned')->count());
    }

    public function test_a_follow_up_reaches_the_person_who_took_over(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'admin' => $admin, 'vp' => $vp, 'head' => $head, 'qa' => $qa] = $this->college();

        $form = $this->makeForm([
            'org_unit_id'      => $office->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $head->id,
            'status'           => 'vp_review',
            'vp_reviewer_id'   => $vp->id,
        ]);

        $vp->update(['status' => 'inactive']);

        $this->actingAsUser($admin);
        $this->postJson("/api/pcr-forms/{$form->id}/nudge")
            ->assertOk()
            ->assertJsonPath('data', 'nudged');

        $this->assertSame('qa_rating', $form->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $qa->id,
            'form_id' => $form->id,
            'type'    => 'nudge',
        ]);
        $this->assertFalse(
            Notification::query()->where('user_id', $vp->id)->where('type', 'nudge')->exists()
        );
    }

    public function test_their_own_ipcr_stays_put(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'admin' => $admin, 'faculty' => $faculty] = $this->college();

        $form = $this->makeForm([
            'org_unit_id'      => $office->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $faculty->id,
            'status'           => 'draft',
        ]);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/users/{$faculty->id}")->assertOk();

        $this->assertSame('draft', $form->fresh()->status);
        $this->assertSame($faculty->id, (int) $form->fresh()->user_id);
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        ['admin' => $admin] = $this->college();

        $this->actingAsUser($admin);
        $this->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');

        $this->postJson('/api/users', [
            'id'         => $admin->id,
            'first_name' => 'Ada',
            'last_name'  => 'Admin',
            'email'      => $admin->email,
            'role'       => 'admin',
            'status'     => 'inactive',
        ])->assertStatus(409)->assertJsonPath('message', 'You cannot deactivate your own account.');

        $this->assertSame('active', $admin->fresh()->status);
    }

    private function bearerFor(User $user): string
    {
        $clientId = (string) Str::uuid();

        DB::table('oauth_clients')->insert([
            'id'            => $clientId,
            'name'          => 'Test',
            'redirect_uris' => '[]',
            'grant_types'   => '[]',
            'revoked'       => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $id = Str::random(80);

        DB::table('oauth_access_tokens')->insert([
            'id'         => $id,
            'user_id'    => $user->id,
            'client_id'  => $clientId,
            'name'       => 'occ-pms',
            'scopes'     => '[]',
            'revoked'    => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $payload = rtrim(strtr(base64_encode(json_encode(['jti' => $id])), '+/', '-_'), '=');

        return 'eyJhbGciOiJIUzI1NiJ9.'.$payload.'.sig';
    }
}
