<?php

namespace Tests\Feature;

use App\Models\PcrOutput;
use App\Support\PcrOutline;
use Tests\PmsTestCase;

class OpcrNestingTest extends PmsTestCase
{
    private function college(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year = $this->makeSchoolYear();
        $this->makePeriod($year, 1);

        $president = $this->actingAsRole('president', ['org_unit_id' => $unit->id]);
        $opcr      = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'draft',
        ]);

        return compact('unit', 'year', 'president', 'opcr');
    }

    public function test_an_opcr_can_nest_a_ppa_under_an_mfo(): void
    {
        ['opcr' => $opcr] = $this->college();

        $parentId = $this->postJson('/api/pcr-outputs', [
            'form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Digitalization',
        ])->assertStatus(201)->json('output.id');

        $childId = $this->postJson('/api/pcr-outputs', [
            'form_id'          => $opcr->id,
            'section'          => 'strategic',
            'title'            => 'Entrance Exam',
            'parent_output_id' => $parentId,
        ])->assertStatus(201)->json('output.id');

        $this->assertSame($parentId, (int) PcrOutput::find($childId)->parent_output_id);
        $this->assertSame('Entrance Exam', PcrOutput::find($childId)->title);
    }

    public function test_a_header_without_a_success_indicator_is_ready(): void
    {
        ['opcr' => $opcr] = $this->college();

        $digitalization = PcrOutput::create([
            'form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Digitalization',
        ]);
        $exam = PcrOutput::create([
            'form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Entrance Exam',
            'parent_output_id' => $digitalization->id,
        ]);
        \App\Models\PcrIndicator::create([
            'output_id'   => $exam->id,
            'description' => '<p>75% implementation of an online entrance exam.</p>',
            'accountable' => 'Annabelle T. Verula',
        ]);

        $this->getJson("/api/pcr-forms/{$opcr->id}/readiness")
            ->assertSuccessful()
            ->assertJsonPath('ready', true);
    }

    public function test_outline_numbers_follow_the_paper_form(): void
    {
        ['opcr' => $opcr] = $this->college();

        $one = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Digitalization', 'sort_order' => 1]);
        PcrOutput::create(['form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Entrance Exam', 'parent_output_id' => $one->id, 'sort_order' => 2]);
        PcrOutput::create(['form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Enrollment', 'parent_output_id' => $one->id, 'sort_order' => 3]);
        PcrOutput::create(['form_id' => $opcr->id, 'section' => 'strategic', 'title' => 'Employability Preparation', 'sort_order' => 4]);

        $numbered = PcrOutline::numbered($opcr->outputs()->get());

        $this->assertSame(['1', '1.1', '1.2', '2'], $numbered->pluck('outline_number')->all());
        $this->assertSame('Entrance Exam', $numbered[1]->title);
    }

    public function test_a_nested_ppa_cannot_be_deleted_while_it_still_has_children(): void
    {
        ['opcr' => $opcr] = $this->college();

        $parent = PcrOutput::create(['form_id' => $opcr->id, 'section' => 'core', 'title' => 'Extension']);
        PcrOutput::create([
            'form_id' => $opcr->id, 'section' => 'core', 'title' => 'Linkages',
            'parent_output_id' => $parent->id,
        ]);

        $this->deleteJson("/api/pcr-outputs/{$parent->id}")->assertStatus(409);
    }
}
