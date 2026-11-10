<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * A step added in Setup sits in the chain, is filled by the person named on
 * the unit, and cannot be removed while a form is waiting there.
 */
class ConfigurableReviewStepsTest extends PmsTestCase
{
    public function test_an_added_step_is_a_stop_and_an_empty_one_is_skipped(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);

        $vp          = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $head        = User::factory()->create(['role' => 'program_head']);
        $coordinator = User::factory()->create(['role' => 'program_head', 'name' => 'Cora Coordinator']);
        $admin       = User::factory()->create(['role' => 'admin']);

        $office = $this->makeUnit([
            'name'         => 'Research and Extension Office',
            'code'         => 'RES',
            'parent_id'    => $college->id,
            'head_user_id' => $head->id,
            'vp_user_id'   => $vp->id,
        ]);

        $head->update(['org_unit_id' => $office->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        $step = [
            'status'    => 'step_coordinator',
            'label'     => 'With Coordinator',
            'source'    => 'unit_slot',
            'slot'      => 'step_coordinator',
            'skippable' => true,
        ];

        $stages = config('pms.review_stages');
        array_splice($stages, count($stages) - 1, 0, [$step]);

        $this->actingAsUser($admin);
        $this->postJson('/api/workflow-settings', [
            'key'   => 'review_stages',
            'value' => $stages,
        ])->assertSuccessful();

        $this->postJson('/api/org-units', [
            'id'           => $office->id,
            'name'         => $office->name,
            'code'         => $office->code,
            'type'         => $office->type,
            'parent_id'    => $office->parent_id,
            'head_user_id' => $head->id,
            'vp_user_id'   => $vp->id,
            'reviewers'    => ['step_coordinator' => $coordinator->id],
        ])->assertSuccessful();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        $this->actingAsUser($faculty);
        $this->documentLine($this->makeIndicator($form, 'support'));
        $this->approveCommitments($form);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertSuccessful();
        $this->assertSame('head_review', $form->fresh()->status);

        $this->actingAsUser($faculty);
        $payload = $this->getJson("/api/pcr-forms/{$form->id}")->assertSuccessful()->json();
        $coordinatorStep = collect($payload['review_steps'])->firstWhere('status', 'step_coordinator');
        $this->assertNotNull($coordinatorStep);
        $this->assertSame('Cora Coordinator', $coordinatorStep['reviewer']['name'] ?? null);

        $indicator = $form->indicators()->first();

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $indicator->id, 'q' => 5, 'e' => 4, 't' => 3]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('vp_review', $form->fresh()->status);

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('step_coordinator', $form->fresh()->status);

        $this->actingAsUser($coordinator);
        $this->getJson('/api/pcr-forms?queue=1')
            ->assertSuccessful()
            ->assertJsonPath('0.id', $form->id);

        $this->actingAsUser($admin);
        $without = array_values(array_filter($stages, fn ($stage) => $stage['status'] !== 'step_coordinator'));
        $this->postJson('/api/workflow-settings', [
            'key'   => 'review_stages',
            'value' => $without,
        ])->assertStatus(422);

        $this->actingAsUser($coordinator);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $form->fresh()->status);

        $empty = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        $this->actingAsUser($admin);
        $this->postJson('/api/org-units', [
            'id'           => $office->id,
            'name'         => $office->name,
            'code'         => $office->code,
            'type'         => $office->type,
            'parent_id'    => $office->parent_id,
            'head_user_id' => $head->id,
            'vp_user_id'   => $vp->id,
            'reviewers'    => ['step_coordinator' => null],
        ])->assertSuccessful();

        $this->actingAsUser($faculty);
        $this->documentLine($this->makeIndicator($empty, 'support'));
        $this->approveCommitments($empty);
        $this->postJson("/api/pcr-forms/{$empty->id}/status", ['status' => 'head_review'])->assertSuccessful();

        $this->actingAsUser($head);
        $line = $empty->indicators()->first();
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $empty->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $line->id, 'q' => 5, 'e' => 4, 't' => 3]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$empty->id}/status", ['status' => 'qa_rating'])->assertSuccessful();

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$empty->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('qa_rating', $empty->fresh()->status);
    }

    public function test_an_empty_extra_step_uses_the_parent(): void
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year);

        $vp          = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $head        = User::factory()->create(['role' => 'program_head']);
        $coordinator = User::factory()->create(['role' => 'program_head', 'name' => 'Parent Coordinator']);
        $admin       = User::factory()->create(['role' => 'admin']);

        $office = $this->makeUnit([
            'name'         => 'Student Support Services',
            'code'         => 'SSS',
            'parent_id'    => $college->id,
            'head_user_id' => $head->id,
            'vp_user_id'   => $vp->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        $step = [
            'status'    => 'step_coordinator',
            'label'     => 'With Coordinator',
            'source'    => 'unit_slot',
            'slot'      => 'step_coordinator',
            'skippable' => true,
        ];
        $stages = config('pms.review_stages');
        array_splice($stages, count($stages) - 1, 0, [$step]);

        $this->actingAsUser($admin);
        $this->postJson('/api/workflow-settings', [
            'key'   => 'review_stages',
            'value' => $stages,
        ])->assertSuccessful();
        $this->postJson('/api/org-units', [
            'id'           => $college->id,
            'name'         => $college->name,
            'code'         => $college->code,
            'type'         => $college->type,
            'reviewers'    => ['step_coordinator' => $coordinator->id],
        ])->assertSuccessful();

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);

        $this->actingAsUser($faculty);
        $this->documentLine($this->makeIndicator($form, 'support'));
        $this->approveCommitments($form);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertSuccessful();

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $form->indicators()->first()->id, 'q' => 5, 'e' => 4, 't' => 3]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();

        $this->actingAsUser($vp);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'qa_rating'])->assertSuccessful();
        $this->assertSame('step_coordinator', $form->fresh()->status);

        $this->actingAsUser($coordinator);
        $this->getJson('/api/pcr-forms?queue=1')
            ->assertSuccessful()
            ->assertJsonPath('0.id', $form->id);
    }
}
