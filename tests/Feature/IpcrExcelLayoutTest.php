<?php

namespace Tests\Feature;

use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\PmsTestCase;

class IpcrExcelLayoutTest extends PmsTestCase
{
    public function test_ipcr_excel_uses_the_madriaga_merges_and_the_forms_names(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year = $this->makeSchoolYear(['label' => '2026', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31']);
        $period = $this->makePeriod($year, 1);

        $owner = User::factory()->create([
            'role' => 'employee', 'name' => 'Ruben B. Madriaga', 'org_unit_id' => $unit->id,
        ]);
        $supervisor = User::factory()->create([
            'role' => 'program_head', 'name' => 'Dr. Wenie Rose D. Canay', 'org_unit_id' => $unit->id,
        ]);
        $approver = User::factory()->create([
            'role' => 'president', 'name' => 'Dr. Neilson D. Bation', 'org_unit_id' => $unit->id,
        ]);

        $form = $this->makeForm([
            'type'             => 'ipcr',
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'rating_period_id' => $period->id,
            'user_id'          => $owner->id,
            'status'           => 'approved',
            'head_reviewer_id' => $supervisor->id,
            'vp_reviewer_id'   => $approver->id,
            'submitted_at'     => '2026-08-10 08:00:00',
            'reviewed_at'      => '2026-08-12 08:00:00',
            'vp_reviewed_at'   => '2026-08-15 08:00:00',
        ]);

        $this->actingAsUser($owner);

        $response = $this->get("/api/pcr-forms/{$form->id}/xlsx?rating_period_id={$period->id}");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'ipcr').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        $merges = $sheet->getMergeCells();

        $this->assertSame('RUBEN B. MADRIAGA', $sheet->getCell('G3')->getValue());
        $this->assertSame('Ratee', $sheet->getCell('G4')->getValue());
        $this->assertSame('Date: 10 August 2026', $sheet->getCell('G5')->getValue());
        $this->assertSame('DR. WENIE ROSE D. CANAY', $sheet->getCell('A9')->getValue());
        $this->assertSame('12 August 2026', $sheet->getCell('D7')->getValue());
        $this->assertSame('DR. NEILSON D. BATION', $sheet->getCell('E9')->getValue());
        $this->assertSame('15 August 2026', $sheet->getCell('L7')->getValue());
        $this->assertSame('Immediate Supervisor', $sheet->getCell('A10')->getValue());
        $this->assertSame('Head of Office', $sheet->getCell('E10')->getValue());

        $this->assertSame('Discussed with', $sheet->getCell('A18')->getValue());
        $this->assertSame('RUBEN B. MADRIAGA', $sheet->getCell('A20')->getValue());
        $this->assertSame('Employee', $sheet->getCell('A21')->getValue());
        $this->assertSame('Supervisor', $sheet->getCell('D21')->getValue());
        $this->assertSame('DR. NEILSON D. BATION', $sheet->getCell('H20')->getValue());
        $this->assertSame('Head of Office', $sheet->getCell('H21')->getValue());
        $this->assertStringContainsString('I certify that I discussed', (string) $sheet->getCell('D19')->getValue());

        foreach ([
            'G3:M3', 'G5:M5', 'A6:C6', 'E6:K6', 'L6:M6',
            'A7:C8', 'D7:D9', 'E7:K8', 'L7:M9',
            'A9:C9', 'E9:K9', 'A10:C10', 'E10:K10', 'L10:M10',
            'A18:B18', 'D18:F18', 'H18:L18',
            'C19:C21', 'D19:F20', 'A20:B20', 'H20:L20',
            'A21:B21', 'D21:F21', 'H21:L21',
        ] as $range) {
            $this->assertContains($range, $merges, $range);
        }

        $this->assertNotContains('L9:M9', $merges);
        $this->assertEqualsWithDelta(45.57, $sheet->getColumnDimension('A')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(8.71, $sheet->getColumnDimension('C')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(105.14, $sheet->getColumnDimension('M')->getWidth(), 0.01);

        unlink($path);
    }
}
