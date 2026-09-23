<?php

namespace Tests\Feature;

use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrComment;
use App\Models\PcrIndicator;
use App\Models\PcrStatusLog;
use App\Models\User;
use App\Services\PcrAssignmentService;
use Tests\PmsTestCase;

class FormHistoryTest extends PmsTestCase
{
    private function office(): array
    {
        $this->makeOrganization();

        $unit = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 1);

        $president = User::factory()->create(['role' => 'president', 'org_unit_id' => $unit->id]);
        $staff     = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $unit->id,
            'school_year_id' => $year->id, 'status' => 'published',
        ]);

        $target = $this->makeIndicator($opcr, 'core');

        return compact('unit', 'year', 'period', 'president', 'staff', 'opcr', 'target');
    }

    public function test_the_history_gathers_every_kind_of_movement(): void
    {
        ['president' => $president, 'staff' => $staff, 'opcr' => $opcr, 'target' => $target, 'period' => $period] = $this->office();

        PcrStatusLog::record($opcr->id, 'approved', 'published', 'Targets published');

        app(PcrAssignmentService::class)->assignIndicator($target, $staff, $president);

        $target->update(['progress_status' => 'completed', 'progress_pct' => 100, 'completed_on' => now()->toDateString()]);

        $accomplishment = PcrAccomplishment::create([
            'indicator_id'          => $target->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => '<p>Delivered.</p>',
        ]);

        PcrAttachment::create([
            'accomplishment_id' => $accomplishment->id,
            'file_path'         => 'demo.png',
            'original_name'     => 'Certificate.png',
            'mime'              => 'image/png',
            'file_size'         => 1024,
            'uploaded_by'       => $president->id,
            'uploaded_by_name'  => $president->name,
        ]);

        PcrComment::create([
            'form_id'     => $opcr->id,
            'stage'       => 'published',
            'body'        => '<p>Targets look complete.</p>',
            'author_id'   => $president->id,
            'author_name' => $president->name,
            'author_role' => 'president',
        ]);

        $this->actingAsUser($president);

        $data = $this->getJson("/api/pcr-forms/{$opcr->id}/history")
            ->assertSuccessful()
            ->json();

        $kinds = array_count_values(array_column($data['events'], 'kind'));

        $this->assertArrayHasKey('movement', $kinds);
        $this->assertArrayHasKey('remark', $kinds);
        $this->assertArrayHasKey('evidence', $kinds);
        $this->assertArrayHasKey('delegation', $kinds);
        $this->assertArrayHasKey('progress', $kinds);

        $delegation = collect($data['events'])->firstWhere('kind', 'delegation');

        $this->assertSame($staff->name, $delegation['detail']);
        $this->assertSame($president->name, $delegation['actor']);

        $this->assertNotNull($data['stages']['published_at']);

        // Newest first.
        $times = array_column($data['events'], 'at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function test_a_stranger_cannot_read_the_history(): void
    {
        ['opcr' => $opcr] = $this->office();

        $outsider = User::factory()->create(['role' => 'employee', 'org_unit_id' => $this->makeUnit(['code' => 'OTH'])->id]);

        $this->actingAsUser($outsider);

        $this->getJson("/api/pcr-forms/{$opcr->id}/history")->assertStatus(403);
    }
}
