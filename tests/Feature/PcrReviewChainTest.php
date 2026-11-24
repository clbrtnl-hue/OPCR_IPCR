<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\OrgUnitReviewer;
use App\Models\PcrRating;
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
        $period  = $this->makePeriod($year);

        return compact('college', 'office', 'year', 'period', 'president', 'vp', 'head', 'faculty');
    }

    private function submit(User $ratee, $form)
    {
        $this->actingAsUser($ratee);
        $this->documentLine($this->makeIndicator($form, 'support'));
        $this->approveCommitments($form);

        return $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review']);
    }

    public function test_faculty_go_to_their_head_then_the_vp(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        $this->submit($faculty, $form)->assertSuccessful();
        $this->assertSame('head_review', $form->fresh()->status);

        $indicator = $form->indicators()->first();

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

    public function test_scores_sent_on_stay_on_the_form_history(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
            'rating_period_id' => $period->id,
        ]);

        $this->submit($faculty, $form)->assertSuccessful();
        $indicator = $form->indicators()->first();

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [[
                'indicator_id' => $indicator->id,
                'q'            => 5,
                'e'            => 4,
                't'            => 3,
                'remarks'      => '<p>Kept the publications on schedule.</p>',
            ]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();

        $this->actingAsUser($vp);
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $indicator->id, 'q' => 3, 'e' => 3, 't' => 3]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();

        $this->assertSame(1, PcrRating::where('indicator_id', $indicator->id)->count());
        $this->assertSame(3, PcrRating::where('indicator_id', $indicator->id)->value('q'));

        $this->actingAsUser($faculty);
        $events = collect($this->getJson("/api/pcr-forms/{$form->id}/history")->assertOk()->json('events'));

        $fromHead = $events->first(fn ($event) => ($event['from'] ?? null) === 'head_review' && ($event['to'] ?? null) === 'vp_review');
        $fromVp   = $events->first(fn ($event) => ($event['from'] ?? null) === 'vp_review' && ($event['to'] ?? null) === 'qa_rating');

        $this->assertSame(5, $fromHead['scores'][0]['q']);
        $this->assertSame(4, $fromHead['scores'][0]['e']);
        $this->assertSame('Kept the publications on schedule.', $fromHead['scores'][0]['remarks']);
        $this->assertSame($head->name, $fromHead['actor']);

        $this->assertSame(3, $fromVp['scores'][0]['q']);
        $this->assertSame($vp->name, $fromVp['actor']);
        $this->assertArrayNotHasKey('remarks', $fromVp['scores'][0]);
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

    public function test_a_head_of_two_units_under_different_vps_goes_straight_to_qa(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $this->makePeriod($year);
        $sasVp   = User::factory()->create(['role' => 'vp']);
        $afsVp   = User::factory()->create(['role' => 'vp']);
        $head    = User::factory()->create(['role' => 'program_head']);

        $sas = $this->makeUnit([
            'name' => 'Student Affairs', 'code' => 'SAS', 'parent_id' => $college->id, 'vp_user_id' => $sasVp->id,
        ]);
        $afs = $this->makeUnit([
            'name' => 'Admin and Finance', 'code' => 'AFS', 'parent_id' => $college->id, 'vp_user_id' => $afsVp->id,
        ]);
        $support = $this->makeUnit([
            'name' => 'Information and Support', 'code' => 'ISS', 'parent_id' => $sas->id, 'head_user_id' => $head->id,
        ]);
        $maintenance = $this->makeUnit([
            'name' => 'Information and Maintenance', 'code' => 'IMC', 'parent_id' => $afs->id, 'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $support->id]);

        $staffA = User::factory()->create(['role' => 'employee', 'org_unit_id' => $support->id]);
        $staffB = User::factory()->create(['role' => 'employee', 'org_unit_id' => $maintenance->id]);

        $own = $this->makeForm([
            'org_unit_id' => $support->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $this->submit($head, $own)->assertSuccessful();
        $this->assertSame('qa_rating', $own->fresh()->status);
        $this->assertNull($own->fresh()->head_reviewer_id);
        $this->assertNull($own->fresh()->vp_reviewer_id);

        $formA = $this->makeForm([
            'org_unit_id' => $support->id, 'school_year_id' => $year->id, 'user_id' => $staffA->id,
        ]);
        $this->submit($staffA, $formA)->assertSuccessful();
        $this->assertSame('head_review', $formA->fresh()->status);
        $this->assertSame($head->id, $formA->fresh()->head_reviewer_id);

        $formB = $this->makeForm([
            'org_unit_id' => $maintenance->id, 'school_year_id' => $year->id, 'user_id' => $staffB->id,
        ]);
        $this->submit($staffB, $formB)->assertSuccessful();
        $this->assertSame('head_review', $formB->fresh()->status);
        $this->assertSame($head->id, $formB->fresh()->head_reviewer_id);
    }

    public function test_a_head_of_two_units_under_the_same_vp_still_goes_to_that_vp(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC2', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $this->makePeriod($year);
        $vp      = User::factory()->create(['role' => 'vp']);
        $head    = User::factory()->create(['role' => 'program_head']);

        $service = $this->makeUnit([
            'name' => 'Student Affairs', 'code' => 'SAS2', 'parent_id' => $college->id, 'vp_user_id' => $vp->id,
        ]);
        $one = $this->makeUnit([
            'name' => 'Guidance', 'code' => 'GCS', 'parent_id' => $service->id, 'head_user_id' => $head->id,
        ]);
        $this->makeUnit([
            'name' => 'Library', 'code' => 'LIB', 'parent_id' => $service->id, 'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $one->id]);

        $form = $this->makeForm([
            'org_unit_id' => $one->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);

        $this->submit($head, $form)->assertSuccessful();
        $this->assertSame('vp_review', $form->fresh()->status);
        $this->assertSame($vp->id, $form->fresh()->vp_reviewer_id);
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
        ['office' => $office, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $qa = User::factory()->create(['role' => 'qa']);
        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);

        $indicator = $form->indicators()->first();

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
        ['office' => $office, 'year' => $year, 'period' => $period, 'head' => $head, 'vp' => $vp] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $this->submit($head, $form);
        $this->assertSame('vp_review', $form->fresh()->status);

        $indicator = $form->indicators()->first();

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
        ['office' => $office, 'year' => $year, 'period' => $period, 'president' => $president] = $this->college();

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
            'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $form->indicators()->first()->id, 'q' => 5, 'e' => 5, 't' => 5]],
        ])->assertStatus(409);
        $this->assertSame([], $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json());
    }

    public function test_qa_rates_their_staff_and_finalizes_without_the_president(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'president' => $president] = $this->college();

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

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Rate this IPCR and finalize it. It is not sent on to a dean, a director, a VP, or the president.');

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

    public function test_faculty_go_to_the_dean_before_the_vp(): void
    {
        ['college' => $college, 'year' => $year, 'period' => $period, 'vp' => $vp] = $this->college();

        $dean = User::factory()->create(['role' => 'program_head', 'position_title' => 'Dean']);
        $head = User::factory()->create(['role' => 'program_head', 'position_title' => 'Program Head, BSIT']);
        $program = $this->makeUnit([
            'name' => 'Information Technology', 'code' => 'BSIT', 'type' => 'program',
            'parent_id' => $college->id,
            'head_user_id' => $head->id,
            'dean_user_id' => $dean->id,
            'vp_user_id' => $vp->id,
        ]);
        $head->update(['org_unit_id' => $program->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $program->id]);

        $form = $this->makeForm([
            'org_unit_id' => $program->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);
        $this->assertSame('head_review', $form->fresh()->status);

        $indicator = $form->indicators()->first();
        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 4]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();
        $this->assertSame('dean_review', $form->fresh()->status);

        $this->actingAsUser($vp);
        $this->assertNotContains($form->id, array_column($this->getJson('/api/pcr-forms?queue=1')->json(), 'id'));

        $this->actingAsUser($dean);
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful()->json();
        $this->assertSame([$form->id], array_column($queue, 'id'));
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('vp_review', $form->fresh()->status);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $form->fresh()->status);
    }

    public function test_a_deans_own_ipcr_goes_to_the_vp_before_qa(): void
    {
        ['college' => $college, 'year' => $year, 'vp' => $vp] = $this->college();

        $dean = User::factory()->create([
            'role' => 'program_head', 'position_title' => 'Dean, Academic Affairs',
        ]);
        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs and Services', 'code' => 'AAS', 'type' => 'office',
            'parent_id' => $college->id,
            'head_user_id' => $dean->id,
            'vp_user_id' => $vp->id,
        ]);
        $dean->update(['org_unit_id' => $affairs->id]);

        $form = $this->makeForm([
            'org_unit_id' => $affairs->id, 'school_year_id' => $year->id, 'user_id' => $dean->id,
        ]);
        $this->submit($dean, $form);

        $fresh = $form->fresh();
        $this->assertSame('vp_review', $fresh->status);
        $this->assertNull($fresh->head_reviewer_id);
        $this->assertNull($fresh->dean_reviewer_id);
        $this->assertSame($vp->id, $fresh->vp_reviewer_id);

        $this->actingAsUser($dean);
        $this->assertNotContains($form->id, array_column($this->getJson('/api/pcr-forms?queue=1')->json(), 'id'));
    }

    public function test_an_empty_dean_and_vp_are_taken_from_the_parent(): void
    {
        ['college' => $college, 'year' => $year, 'period' => $period] = $this->college();

        $vp   = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $dean = User::factory()->create(['role' => 'program_head', 'position_title' => 'Dean']);
        $head = User::factory()->create(['role' => 'program_head', 'position_title' => 'Program Head']);

        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office',
            'parent_id' => $college->id,
            'vp_user_id' => $vp->id,
        ]);
        $cit = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'type' => 'office',
            'parent_id' => $affairs->id,
            'dean_user_id' => $dean->id,
        ]);
        $dit = $this->makeUnit([
            'name' => 'Department of IT', 'code' => 'DIT', 'type' => 'program',
            'parent_id' => $cit->id,
            'head_user_id' => $head->id,
        ]);
        $head->update(['org_unit_id' => $dit->id]);
        $dean->update(['org_unit_id' => $cit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $dit->id]);

        $form = $this->makeForm([
            'org_unit_id' => $dit->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);
        $this->assertSame('head_review', $form->fresh()->status);
        $this->assertSame($head->id, $form->fresh()->head_reviewer_id);
        $this->assertSame($dean->id, $form->fresh()->dean_reviewer_id);
        $this->assertSame($vp->id, $form->fresh()->vp_reviewer_id);

        $indicator = $form->indicators()->first();
        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 4]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();
        $this->assertSame('dean_review', $form->fresh()->status);

        $this->actingAsUser($dean);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('vp_review', $form->fresh()->status);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $form->fresh()->status);
    }

    public function test_a_child_vp_overrides_the_parent(): void
    {
        ['college' => $college, 'year' => $year] = $this->college();

        $parentVp = User::factory()->create(['role' => 'vp', 'name' => 'Parent VP']);
        $childVp  = User::factory()->create(['role' => 'vp', 'name' => 'Child VP']);
        $head     = User::factory()->create(['role' => 'program_head']);

        $affairs = $this->makeUnit([
            'name' => 'Academic Affairs', 'code' => 'AAS', 'type' => 'office',
            'parent_id' => $college->id,
            'vp_user_id' => $parentVp->id,
        ]);
        $dit = $this->makeUnit([
            'name' => 'Department of IT', 'code' => 'DIT', 'type' => 'program',
            'parent_id' => $affairs->id,
            'head_user_id' => $head->id,
            'vp_user_id' => $childVp->id,
        ]);
        $head->update(['org_unit_id' => $dit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $dit->id]);

        $form = $this->makeForm([
            'org_unit_id' => $dit->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);

        $fresh = $form->fresh();
        $this->assertSame($childVp->id, $fresh->vp_reviewer_id);
        $this->assertNotSame($parentVp->id, $fresh->vp_reviewer_id);
    }

    public function test_an_empty_head_is_not_taken_from_the_parent(): void
    {
        ['college' => $college, 'year' => $year] = $this->college();

        $vp         = User::factory()->create(['role' => 'vp']);
        $parentHead = User::factory()->create(['role' => 'program_head', 'name' => 'College Head']);
        $dean       = User::factory()->create(['role' => 'program_head', 'name' => 'College Dean']);

        $cit = $this->makeUnit([
            'name' => 'College of IT', 'code' => 'CIT', 'type' => 'office',
            'parent_id' => $college->id,
            'head_user_id' => $parentHead->id,
            'dean_user_id' => $dean->id,
            'vp_user_id' => $vp->id,
        ]);
        $dis = $this->makeUnit([
            'name' => 'Department of IS', 'code' => 'DIS', 'type' => 'program',
            'parent_id' => $cit->id,
        ]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $dis->id]);

        $form = $this->makeForm([
            'org_unit_id' => $dis->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);

        $fresh = $form->fresh();
        $this->assertSame('dean_review', $fresh->status);
        $this->assertNull($fresh->head_reviewer_id);
        $this->assertSame($dean->id, $fresh->dean_reviewer_id);
        $this->assertSame($vp->id, $fresh->vp_reviewer_id);
    }

    public function test_an_office_goes_to_the_director_not_the_dean(): void
    {
        ['college' => $college, 'year' => $year, 'period' => $period] = $this->college();

        $vp       = User::factory()->create(['role' => 'vp']);
        $director = User::factory()->create(['role' => 'program_head', 'name' => 'Office Director']);
        $head     = User::factory()->create(['role' => 'program_head']);

        $office = $this->makeUnit([
            'name' => 'Center for ICT', 'code' => 'CICT', 'type' => 'office',
            'parent_id' => $college->id,
            'head_user_id' => $head->id,
            'vp_user_id' => $vp->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);
        $director->update(['org_unit_id' => $office->id]);
        OrgUnitReviewer::updateOrCreate(
            ['org_unit_id' => $office->id, 'slot' => 'director_review'],
            ['user_id' => $director->id],
        );
        $staff = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->submit($staff, $form);
        $this->assertSame('head_review', $form->fresh()->status);
        $this->assertNull($form->fresh()->dean_reviewer_id);

        $indicator = $form->indicators()->first();
        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 4]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();
        $this->assertSame('director_review', $form->fresh()->status);

        $this->actingAsUser($director);
        $arrived = $form->statusLogs()->where('to_status', 'director_review')->latest('id')->value('created_at');
        $queue = $this->getJson('/api/pcr-forms?queue=1')->assertSuccessful();
        $queue->assertJsonPath('0.id', $form->id);
        $this->assertSame(
            $arrived->format('Y-m-d H:i:s'),
            \Illuminate\Support\Carbon::parse($queue->json('0.stage_arrived_at'))->format('Y-m-d H:i:s')
        );
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('vp_review', $form->fresh()->status);
    }

    public function test_the_owner_may_optionally_self_rate_before_review(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $qa = User::factory()->create(['role' => 'qa']);
        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $indicator = $this->documentLine($this->makeIndicator($form, 'support'));

        $this->actingAsUser($faculty);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 5, 't' => 5, 'remarks' => 'My own read.']],
        ])->assertStatus(409);

        $this->approveCommitments($form);

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 1, 'e' => 1, 't' => 1]],
        ])->assertStatus(409);

        $this->actingAsUser($faculty);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 3, 'remarks' => 'My own read.']],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertSuccessful();

        $events = collect($this->getJson("/api/pcr-forms/{$form->id}/history")->assertOk()->json('events'));
        $own = $events->first(fn ($event) => ($event['from'] ?? null) === 'commitments_approved');

        $this->assertSame($faculty->name, $own['actor']);
        $this->assertSame(5, $own['scores'][0]['q']);
        $this->assertSame(4, $own['scores'][0]['e']);
        $this->assertSame('My own read.', $own['scores'][0]['remarks']);

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();
        $this->assertSame(5, PcrRating::where('indicator_id', $indicator->id)->value('q'));

        $this->actingAsUser($vp);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 3, 'e' => 3, 't' => 3]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();

        $this->actingAsUser($qa);
        $this->postJson("/api/pcr-forms/{$form->id}/finalize-rating", [
            'rating_period_id' => $period->id,
            'keep'             => true,
        ])->assertOk();

        $fresh = $form->fresh();
        $this->assertSame('rated', $fresh->status);
        $this->assertSame($qa->id, (int) $fresh->rated_by);
        $this->assertSame($qa->name, $fresh->rated_by_name);
        $this->assertDatabaseHas('pcr_ratings', [
            'indicator_id' => $indicator->id,
            'q'            => 3,
            'rated_by'     => $vp->id,
        ]);

        $this->actingAsUser($faculty);
        $kept = collect($this->getJson("/api/pcr-forms/{$form->id}/history")->assertOk()->json('events'))
            ->first(fn ($event) => ($event['to'] ?? null) === 'rated');

        $this->assertStringContainsString('Kept the rating already on the form.', $kept['detail']);
        $this->assertArrayNotHasKey('scores', $kept);
    }

    public function test_qa_may_replace_the_rating_that_reaches_them(): void
    {
        ['office' => $office, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'head' => $head, 'vp' => $vp] = $this->college();

        $qa = User::factory()->create(['role' => 'qa']);
        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form);
        $indicator = $form->indicators()->first();

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
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();

        $this->actingAsUser($qa);
        $this->postJson('/api/pcr-ratings', [
            'form_id' => $form->id, 'rating_period_id' => $period->id,
            'ratings' => [['indicator_id' => $indicator->id, 'q' => 4, 'e' => 4, 't' => 4, 'remarks' => 'Adjusted.']],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/finalize-rating", [
            'rating_period_id' => $period->id,
        ])->assertOk();

        $fresh = $form->fresh();
        $this->assertSame('rated', $fresh->status);
        $this->assertSame($qa->name, $fresh->rated_by_name);
        $this->assertDatabaseHas('pcr_ratings', [
            'indicator_id' => $indicator->id,
            'q'            => 4,
            'rated_by'     => $qa->id,
        ]);

        $this->actingAsUser($faculty);
        $replaced = collect($this->getJson("/api/pcr-forms/{$form->id}/history")->assertOk()->json('events'))
            ->first(fn ($event) => ($event['from'] ?? null) === 'qa_rating' && ($event['to'] ?? null) === 'rated');

        $this->assertSame(4, $replaced['scores'][0]['q']);
        $this->assertSame('Adjusted.', $replaced['scores'][0]['remarks']);
        $this->assertSame($qa->name, $replaced['actor']);
    }

    public function test_submitting_without_a_self_rating_logs_no_scores(): void
    {
        ['office' => $office, 'year' => $year, 'faculty' => $faculty] = $this->college();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $this->submit($faculty, $form)->assertSuccessful();

        $this->actingAsUser($faculty);
        $sent = collect($this->getJson("/api/pcr-forms/{$form->id}/history")->assertOk()->json('events'))
            ->first(fn ($event) => ($event['from'] ?? null) === 'commitments_approved');

        $this->assertArrayNotHasKey('scores', $sent);
    }
}
