<?php

namespace Tests\Feature;

use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\User;
use Tests\PmsTestCase;

class FormTemplateTest extends PmsTestCase
{
    private function draft(): array
    {
        $this->makeOrganization();

        $unit    = $this->makeUnit();
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year, 1);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $faculty->id,
            'rating_period_id' => $period->id,
        ]);

        return compact('unit', 'year', 'period', 'faculty', 'form');
    }

    private function template(): array
    {
        return collect(config('pms_templates'))->firstWhere('key', 'support-standard');
    }

    public function test_the_templates_are_listed(): void
    {
        $this->makeOrganization();
        $this->actingAsRole('employee');

        $response = $this->getJson('/api/form-templates')->assertOk();

        $this->assertNotEmpty($response->json());
        $this->assertSame('support-standard', $response->json('0.key'));
        $this->assertSame('support', $response->json('0.section'));
    }

    public function test_inserting_a_template_writes_its_headings_and_lines(): void
    {
        ['form' => $form, 'faculty' => $faculty, 'period' => $period] = $this->draft();

        $this->actingAsUser($faculty);

        $expected = collect($this->template()['outputs']);

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'support-standard'])
            ->assertOk()
            ->assertJsonPath('outputs', $expected->count())
            ->assertJsonPath('lines', $expected->sum(fn ($block) => count($block['indicators'])));

        $outputs = PcrOutput::where('form_id', $form->id)->get();

        $this->assertCount($expected->count(), $outputs);
        $this->assertTrue($outputs->every(fn ($output) => $output->section === 'support'));

        $lines = PcrIndicator::whereIn('output_id', $outputs->pluck('id'))->get();

        $this->assertTrue($lines->every(fn ($line) => (int) $line->rating_period_id === $period->id));
    }

    public function test_inserting_it_twice_adds_nothing_new(): void
    {
        ['form' => $form, 'faculty' => $faculty] = $this->draft();

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'support-standard'])->assertOk();

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'support-standard'])
            ->assertOk()
            ->assertJsonPath('outputs', 0)
            ->assertJsonPath('lines', 0);

        $this->assertSame(
            collect($this->template()['outputs'])->count(),
            PcrOutput::where('form_id', $form->id)->count()
        );
    }

    public function test_it_is_appended_to_work_that_is_already_there(): void
    {
        ['form' => $form, 'faculty' => $faculty] = $this->draft();

        $this->makeIndicator($form, 'core');

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'support-standard'])->assertOk();

        $this->assertSame(1, PcrOutput::where('form_id', $form->id)->where('section', 'core')->count());
        $this->assertSame(
            collect($this->template()['outputs'])->count(),
            PcrOutput::where('form_id', $form->id)->where('section', 'support')->count()
        );
    }

    public function test_a_template_that_does_not_exist_is_refused(): void
    {
        ['form' => $form, 'faculty' => $faculty] = $this->draft();

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'made-up'])
            ->assertStatus(422);
    }

    public function test_a_colleague_cannot_write_into_your_form(): void
    {
        ['form' => $form, 'unit' => $unit] = $this->draft();

        $other = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $this->actingAsUser($other);

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'support-standard'])
            ->assertStatus(409);
    }

    public function test_a_submitted_form_is_closed_to_templates(): void
    {
        ['form' => $form, 'faculty' => $faculty] = $this->draft();

        $form->update(['status' => 'head_review']);

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$form->id}/apply-template", ['template' => 'support-standard'])
            ->assertStatus(409);
    }

    public function test_an_opcr_takes_a_template_year_wide(): void
    {
        ['unit' => $unit, 'year' => $year] = $this->draft();

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'user_id' => null,
        ]);

        $this->actingAsRole('president', ['org_unit_id' => $unit->id]);

        $this->postJson("/api/pcr-forms/{$opcr->id}/apply-template", ['template' => 'opcr-support'])
            ->assertOk()
            ->assertJsonPath('outputs', 5)
            ->assertJsonPath('lines', 0);

        $outputs = PcrOutput::where('form_id', $opcr->id)->get();

        $this->assertCount(5, $outputs);
        $this->assertTrue($outputs->every(fn ($output) => $output->section === 'support'));
        $this->assertSame(
            0,
            PcrIndicator::whereIn('output_id', $outputs->pluck('id'))->count()
        );

        $this->postJson("/api/pcr-forms/{$opcr->id}/apply-template", ['template' => 'support-standard'])
            ->assertStatus(422);
    }

    public function test_a_head_gets_the_head_support_mfos_only(): void
    {
        ['unit' => $unit, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'form' => $employeeForm] = $this->draft();

        $head = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $ipcr = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $head->id,
            'rating_period_id' => $period->id,
        ]);

        $this->actingAsUser($head);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/apply-template", ['template' => 'support-head'])
            ->assertOk()
            ->assertJsonPath('outputs', 8)
            ->assertJsonPath('lines', 0);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/apply-template", ['template' => 'support-standard'])
            ->assertStatus(422);

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$employeeForm->id}/apply-template", ['template' => 'support-head'])
            ->assertStatus(422);
    }

    public function test_a_vp_gets_the_vp_support_mfos_only(): void
    {
        ['unit' => $unit, 'year' => $year, 'period' => $period, 'faculty' => $faculty, 'form' => $employeeForm] = $this->draft();

        $vp = User::factory()->create(['role' => 'vp', 'org_unit_id' => $unit->id]);
        $ipcr = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $vp->id,
            'rating_period_id' => $period->id,
        ]);

        $this->actingAsUser($vp);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/apply-template", ['template' => 'support-vp'])
            ->assertOk()
            ->assertJsonPath('outputs', 6)
            ->assertJsonPath('lines', 0);

        $this->postJson("/api/pcr-forms/{$ipcr->id}/apply-template", ['template' => 'support-standard'])
            ->assertStatus(422);

        $this->actingAsUser($faculty);

        $this->postJson("/api/pcr-forms/{$employeeForm->id}/apply-template", ['template' => 'support-vp'])
            ->assertStatus(422);
    }
}
