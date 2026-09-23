<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * Who reviews a form comes from the ratee's place in the hierarchy, not from a
 * fixed role sequence. Faculty go head -> VP -> QA; a program head skips the
 * head stage; a VP goes to QA. QA rates their own IPCR and their staff, and
 * those forms are not sent to the president.
 */
class PcrReviewChainTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $college->id]);
        $vp        = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $head      = User::factory()->create(['role' => 'program_head']);

        $office = $this->makeUnit([
            'name'         => 'Research and Extension Office',
            'code'         => 'RES',
            'parent_id'    => $college->id,
            'head_user_id' => $head->id,
            'vp_user_id'   => $vp->id,
        ]);

        $head->update(['org_unit_id' => $office->id]);

        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        return compact('college', 'office', 'year', 'president', 'vp', 'head', 'faculty');
    }

    private function submit(User $ratee, $form)
    {
        $this->actingAsUser($ratee);
        $this->makeIndicator($form, 'support');

        return $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review']);
    }

    public function test_faculty_go_to_their_head_then_the_vp(): void
    {
        ['office' => $office, 'year' => $year, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        $this->submit($faculty, $form)->assertSuccessful();
        $this->assertSame('head_review', $form->fresh()->status);

        $indicator = $form->indicators()->first();
        $period    = $this->makePeriod($year);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Rate every line before sending this on.');

        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 3]],
        ])->assertOk();

        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();
        $this->assertSame('vp_review', $form->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $faculty->id,
            'form_id' => $form->id,
            'title'   => 'Your IPCR was rated and forwarded to the VP',
        ]);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $form->fresh()->status);
    }

    public function test_a_program_heads_own_ipcr_skips_the_head_stage(): void
    {
        ['office' => $office, 'year' => $year, 'head' => $head] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);

        $this->submit($head, $form)->assertSuccessful();

        // They are the head, so the head stage has nobody to fill it.
        $this->assertSame('vp_review', $form->fresh()->status);
        $this->assertNull($form->fresh()->head_reviewer_id);

        $this->assertDatabaseHas('pcr_status_logs', ['form_id' => $form->id, 'to_status' => 'vp_review']);
        $this->assertSame(
            0,
            Notification::where('user_id', $head->id)
                ->where('title', 'like', 'Your IPCR was rated and forwarded%')
                ->count()
        );
    }

    public function test_a_vps_own_ipcr_goes_straight_to_qa(): void
    {
        ['college' => $college, 'year' => $year, 'vp' => $vp, 'president' => $president] = $this->college();

        // The college unit has no head and no VP of its own — this is the form
        // that used to enter head_review with no reviewer and strand. Nobody
        // sits above a VP, so it goes straight to QA.
        $form = $this->makeForm([
            'org_unit_id' => $college->id, 'school_year_id' => $year->id, 'user_id' => $vp->id,
        ]);

        $this->submit($vp, $form)->assertSuccessful();

        $this->assertSame('qa_rating', $form->fresh()->status);
        $this->assertNull($form->fresh()->head_reviewer_id);
        $this->assertNull($form->fresh()->vp_reviewer_id);

        // The president reviews nothing — their queue stays empty.
        $this->actingAsUser($president);
        $this->assertSame([], $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json());
    }

    public function test_it_appears_in_the_reviewers_queue(): void
    {
        ['office' => $office, 'year' => $year, 'faculty' => $faculty, 'head' => $head] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);

        $this->actingAsUser($head);
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json();

        $this->assertSame([$form->id], array_column($queue, 'id'));
    }

    public function test_one_person_holding_both_posts_sees_both_queues(): void
    {
        ['college' => $college, 'office' => $office, 'year' => $year, 'faculty' => $faculty] = $this->college();

        // Somebody who heads one office and is VP over another.
        $dualHat = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $office->id]);
        $office->update(['head_user_id' => $dualHat->id]);

        $second = $this->makeUnit([
            'name' => 'BS Information Technology', 'code' => 'BSIT',
            'parent_id' => $college->id, 'vp_user_id' => $dualHat->id,
        ]);
        $secondHead = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $second->id]);
        $second->update(['head_user_id' => $secondHead->id]);

        // A faculty form waiting on them as head.
        $asHead = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $asHead);

        // The other office's head submits and is endorsed up to the VP stage.
        $asVp = $this->makeForm([
            'org_unit_id' => $second->id, 'school_year_id' => $year->id, 'user_id' => $secondHead->id,
        ]);
        $this->submit($secondHead, $asVp);

        $this->actingAsUser($dualHat);
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json();

        $ids = array_column($queue, 'id');
        sort($ids);
        $expected = [$asHead->id, $asVp->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_nobody_reviews_their_own_form(): void
    {
        ['office' => $office, 'year' => $year, 'head' => $head] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $this->submit($head, $form);

        $this->actingAsUser($head);
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json();

        $this->assertNotContains($form->id, array_column($queue, 'id'));
    }

    public function test_the_vp_may_replace_the_heads_score_before_qa_does(): void
    {
        ['office' => $office, 'year' => $year, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $qa = User::factory()->create(['role' => 'qa']);
        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);

        $indicator = $form->indicators()->first();
        $period    = $this->makePeriod($year);

        $this->actingAsUser($vp);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 1, 'e' => 1, 't' => 1]],
        ])->assertStatus(409);

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();

        $this->actingAsUser($vp);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 3, 'e' => 3, 't' => 3]],
        ])->assertOk();
        $this->assertDatabaseHas('pcr_ratings', ['indicator_id' => $indicator->id, 'q' => 3, 'e' => 3, 't' => 3]);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();

        $this->actingAsUser($qa);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 4, 'e' => 4, 't' => 4]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertOk();

        $this->assertSame('rated', $form->fresh()->status);
        $this->assertDatabaseHas('pcr_ratings', ['indicator_id' => $indicator->id, 'q' => 4]);
    }

    public function test_the_vp_rates_the_heads_own_ipcr(): void
    {
        ['office' => $office, 'year' => $year, 'head' => $head, 'vp' => $vp] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $this->submit($head, $form);
        $this->assertSame('vp_review', $form->fresh()->status);

        $indicator = $form->indicators()->first();
        $period    = $this->makePeriod($year);

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5]],
        ])->assertStatus(409);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertStatus(422);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 4, 'e' => 4, 't' => 4]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $form->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'form_id' => $form->id,
            'title'   => 'Your IPCR was rated and forwarded to QA',
        ]);
    }

    public function test_qa_rates_their_own_ipcr_and_does_not_send_it_to_the_president(): void
    {
        ['office' => $office, 'year' => $year, 'president' => $president] = $this->college();

        $qa = User::factory()->create(['role' => 'qa', 'org_unit_id' => $office->id]);
        $office->update(['head_user_id' => $qa->id, 'vp_user_id' => $president->id]);

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $qa->id,
        ]);
        $this->submit($qa, $form);

        $fresh = $form->fresh();
        $this->assertSame('qa_rating', $fresh->status);
        $this->assertNull($fresh->vp_reviewer_id);
        $this->assertNull($fresh->head_reviewer_id);

        $this->actingAsUser($president);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id,
            'rating_period_id' => $this->makePeriod($year)->id,
            'ratings' => [['indicator_id' => $form->indicators()->first()->id, 'q' => 5, 'e' => 5, 't' => 5]],
        ])->assertStatus(409);
        $this->assertSame([], $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json());
    }

    public function test_qa_rates_their_staff_and_finalizes_without_the_president(): void
    {
        ['office' => $office, 'year' => $year, 'president' => $president] = $this->college();

        $qa    = User::factory()->create(['role' => 'qa', 'org_unit_id' => $office->id]);
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);
        $office->update(['head_user_id' => $qa->id, 'vp_user_id' => $president->id]);

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->submit($staff, $form);
        $this->assertSame('head_review', $form->fresh()->status);
        $this->assertSame($qa->id, $form->fresh()->head_reviewer_id);

        $indicator = $form->indicators()->first();
        $period    = $this->makePeriod($year);

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Rate this IPCR and finalize it. It is not sent on to a VP or the president.');

        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 5]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertOk();

        $this->assertSame('rated', $form->fresh()->status);

        $this->actingAsUser($president);
        $this->assertSame([], $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json());
    }
}
