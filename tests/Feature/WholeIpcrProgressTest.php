<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\User;
use Tests\PmsTestCase;

/**
 * One finished commitment out of four is 25% for that person, and that whole
 * figure — not only the line a head assigned — is what the head and the VP see.
 */
class WholeIpcrProgressTest extends PmsTestCase
{
    public function test_one_finished_commitment_out_of_four_reads_as_twenty_five_percent(): void
    {
        $this->makeOrganization();

        $office = $this->makeUnit(['name' => 'Office of the College President', 'code' => 'OCP', 'type' => 'college']);
        $aas    = $this->makeUnit(['name' => 'Academic Affairs and Services', 'code' => 'AAS', 'type' => 'office', 'parent_id' => $office->id]);
        $cit    = $this->makeUnit(['name' => 'College of Information Technology', 'code' => 'CIT', 'type' => 'program', 'parent_id' => $aas->id]);
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $office->id]);
        $vp        = User::factory()->create(['role' => 'vp', 'org_unit_id' => $aas->id]);
        $head      = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $cit->id]);
        $faculty   = User::factory()->create(['role' => 'employee', 'org_unit_id' => $cit->id]);

        $aas->update(['vp_user_id' => $vp->id]);
        $cit->update(['head_user_id' => $head->id]);

        $ipcr = $this->makeForm([
            'org_unit_id' => $cit->id, 'school_year_id' => $year->id,
            'rating_period_id' => $period->id, 'user_id' => $faculty->id,
        ]);
        $output = PcrOutput::create(['form_id' => $ipcr->id, 'section' => 'core', 'title' => 'Instruction']);

        $lines = collect(range(1, 4))->map(fn ($n) => PcrIndicator::create([
            'output_id' => $output->id, 'rating_period_id' => $period->id,
            'description' => "Commitment {$n}",
        ]));

        $this->documentLine($lines->first());

        $this->makeForm([
            'org_unit_id' => $cit->id, 'school_year_id' => $year->id,
            'rating_period_id' => $period->id, 'user_id' => $head->id,
        ]);
        $this->makeForm([
            'org_unit_id' => $aas->id, 'school_year_id' => $year->id,
            'rating_period_id' => $period->id, 'user_id' => $vp->id,
        ]);

        $this->actingAsUser($president);

        $data  = $this->getJson("/api/reports/summary?school_year_id={$year->id}")->assertSuccessful()->json();
        $units = collect($data['units'])->keyBy('id');
        $people = collect($data['people'])->keyBy('id');

        $this->assertSame(100, (int) $lines->first()->fresh()->progress_pct);
        $this->assertSame(0, (int) $lines->last()->fresh()->progress_pct);
        $this->assertSame(25, $people[$faculty->id]['progress_pct']);
        $this->assertSame(25, $units[$cit->id]['progress_pct']);
        $this->assertSame(25, $people[$head->id]['progress_pct']);
        $this->assertSame(25, $units[$aas->id]['progress_pct']);
        $this->assertSame(25, $people[$vp->id]['progress_pct']);

        $this->actingAsUser($head);
        $this->getJson("/api/pcr-forms/{$ipcr->id}")->assertSuccessful();

        $this->actingAsUser($vp);
        $this->getJson("/api/pcr-forms/{$ipcr->id}")->assertSuccessful();
    }

    public function test_an_extra_line_does_not_change_the_assigned_target(): void
    {
        $this->makeOrganization();

        $unit   = $this->makeUnit();
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);
        $head   = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['rating_period_id' => $period->id]);

        $admin = $this->actingAsRole('admin');
        app(\App\Services\PcrAssignmentService::class)->assignIndicator($target, $head, $admin);
        $headLine = $this->commitAgainst($target, $head);

        app(\App\Services\PcrAssignmentService::class)->assignIndicator($headLine, $faculty, $head);
        $linked = $this->commitAgainst($headLine, $faculty);
        $this->documentLine($linked);

        $form = PcrForm::where('user_id', $faculty->id)->first();
        $own  = PcrIndicator::create([
            'output_id'        => PcrOutput::where('form_id', $form->id)->value('id'),
            'rating_period_id' => $period->id,
            'description'      => 'My own target, not from the head.',
        ]);

        $this->assertSame(100, (int) $headLine->fresh()->progress_pct);
        $this->assertSame(0, (int) $own->fresh()->progress_pct);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $unit->update(['head_user_id' => $head->id]);

        $this->actingAsUser($president);

        $person = collect($this->getJson("/api/reports/summary?school_year_id={$year->id}")->json('people'))
            ->firstWhere('id', $faculty->id);

        $this->assertSame(50, $person['progress_pct']);
    }
}
