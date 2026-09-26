<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RatingScale;
use App\Services\WorkflowSettings;
use Tests\PmsTestCase;

/**
 * The rules the college runs on are data, not code. Changing them in
 * Setup -> Workflow must actually change how forms behave — otherwise the
 * screen is decoration.
 */
class WorkflowSettingsTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $college = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year    = $this->makeSchoolYear();

        $vp   = User::factory()->create(['role' => 'vp', 'org_unit_id' => $college->id]);
        $head = User::factory()->create(['role' => 'program_head']);

        $office = $this->makeUnit([
            'name' => 'Research and Extension Office', 'code' => 'RES',
            'parent_id' => $college->id, 'head_user_id' => $head->id, 'vp_user_id' => $vp->id,
        ]);
        $head->update(['org_unit_id' => $office->id]);

        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        return compact('college', 'office', 'year', 'vp', 'head', 'faculty');
    }

    public function test_defaults_apply_before_anything_is_configured(): void
    {
        $this->makeOrganization();

        $settings = app(WorkflowSettings::class);

        $this->assertSame(
            ['head_review', 'vp_review', 'qa_rating'],
            array_column($settings->reviewStages(), 'status')
        );
        $this->assertTrue($settings->isTerminalRole('employee'));
    }

    public function test_dropping_the_vp_stage_changes_where_a_form_goes(): void
    {
        ['office' => $office, 'year' => $year, 'faculty' => $faculty, 'head' => $head] = $this->college();

        app(WorkflowSettings::class)->put('review_stages', [
            ['status' => 'head_review', 'label' => 'With Head', 'source' => 'unit_slot',
             'slot' => 'head_user_id', 'skippable' => true],
            ['status' => 'qa_rating', 'label' => 'With QA', 'source' => 'role',
             'role' => 'qa', 'skippable' => false],
        ]);

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $faculty->id,
        ]);
        $period = $this->makePeriod($year);
        $line = $this->documentLine($this->makeIndicator($form, 'support'));

        $this->actingAsUser($faculty);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertSuccessful();

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-ratings', [
            'form_id'          => $form->id,
            'rating_period_id' => $period->id,
            'ratings'          => [['indicator_id' => $line->id, 'q' => 4, 'e' => 4, 't' => 4]],
        ])->assertOk();
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'vp_review'])->assertSuccessful();

        // The VP stage is no longer in the chain, so endorsing lands on QA.
        $this->assertSame('qa_rating', $form->fresh()->status);
    }

    public function test_making_someone_terminal_stops_them_delegating(): void
    {
        ['office' => $office, 'year' => $year, 'head' => $head, 'faculty' => $faculty] = $this->college();

        $form   = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $head->id,
        ]);
        $line   = $this->makeIndicator($form, 'core');

        $this->actingAsUser($head);
        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$faculty->id]])
            ->assertStatus(201);

        // Now say program heads are the bottom of the chain.
        app(WorkflowSettings::class)->put('delegation', [
            'assign_outputs'    => ['president'],
            'assign_indicators' => ['president'],
            'terminal_roles'    => ['employee', 'program_head'],
        ]);

        $other = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);

        $this->postJson("/api/pcr-indicators/{$line->id}/assign", ['user_ids' => [$other->id]])
            ->assertStatus(403);
    }

    public function test_opcr_creator_role_is_configurable(): void
    {
        ['college' => $college, 'year' => $year, 'head' => $head] = $this->college();

        $this->actingAsUser($head);
        $this->postJson('/api/pcr-forms', [
            'type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $college->id,
        ])->assertStatus(403);

        app(WorkflowSettings::class)->put('opcr', [
            'creator_roles'        => ['president', 'program_head'],
            'approver_roles'       => ['qa'],
            'publisher_roles'      => ['president'],
            'rating_trigger_roles' => ['president'],
            'one_per'              => 'organization',
        ]);

        $this->postJson('/api/pcr-forms', [
            'type' => 'opcr', 'school_year_id' => $year->id, 'org_unit_id' => $college->id,
        ])->assertStatus(201);
    }

    public function test_rating_bands_are_configurable(): void
    {
        $this->makeOrganization();

        $this->assertSame('Outstanding', RatingScale::adjectival(4.8));

        app(WorkflowSettings::class)->put('rating', [
            'dimensions' => ['q', 'e', 't'],
            'min' => 1, 'max' => 5,
            'bands' => [
                ['min' => 4.0, 'label' => 'Excellent', 'value' => 5],
                ['min' => 0.0, 'label' => 'Needs work', 'value' => 1],
            ],
        ]);

        $this->assertSame('Excellent', RatingScale::adjectival(4.8));
        $this->assertSame('Needs work', RatingScale::adjectival(3.9));
    }

    public function test_rules_that_would_strand_work_are_refused(): void
    {
        $this->makeOrganization();
        $this->actingAsRole('admin');

        // A chain whose last stage may be skipped could never close a form.
        $this->postJson('/api/workflow-settings', [
            'key'   => 'review_stages',
            'value' => [
                ['status' => 'head_review', 'label' => 'Head', 'source' => 'unit_slot',
                 'slot' => 'head_user_id', 'skippable' => true],
            ],
        ])->assertStatus(422);

        $this->postJson('/api/workflow-settings', [
            'key'   => 'opcr',
            'value' => ['creator_roles' => [], 'approver_roles' => ['qa'],
                        'publisher_roles' => ['president'], 'rating_trigger_roles' => ['president']],
        ])->assertStatus(422);
    }

    public function test_only_an_admin_may_change_the_rules(): void
    {
        ['head' => $head] = $this->college();

        $this->actingAsUser($head);
        $this->getJson('/api/workflow-settings')->assertStatus(403);
    }

    public function test_a_section_can_be_restored_to_the_default(): void
    {
        $this->makeOrganization();
        $settings = app(WorkflowSettings::class);

        $settings->put('delegation', ['assign_outputs' => ['program_head'], 'assign_indicators' => [], 'terminal_roles' => []]);
        $this->assertTrue($settings->mayAssignOutputs('program_head'));

        $this->actingAsRole('admin');
        $this->postJson('/api/workflow-settings/reset', ['key' => 'delegation'])->assertSuccessful();

        $this->assertTrue(app(WorkflowSettings::class)->mayAssignOutputs('president'));
    }
}
