<?php

namespace Tests\Feature;

use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\PmsTestCase;

class OpcrExcelLayoutTest extends PmsTestCase
{
    public function test_opcr_excel_places_names_and_dates_on_the_v3_merges(): void
    {
        $this->makeOrganization();
        $unit = $this->makeUnit(['name' => 'Opol Community College', 'code' => 'OCC', 'type' => 'college']);
        $year = $this->makeSchoolYear(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $president = User::factory()->create([
            'role'           => 'president',
            'name'           => 'Neilson D. Bation, DM',
            'position_title' => 'College President',
            'org_unit_id'    => $unit->id,
        ]);
        $approver = User::factory()->create([
            'role'           => 'vp',
            'name'           => 'Atty. Kenneth M. Kempis',
            'position_title' => 'Municipal Administrator',
            'org_unit_id'    => $unit->id,
        ]);

        $form = $this->makeForm([
            'type'           => 'opcr',
            'org_unit_id'    => $unit->id,
            'school_year_id' => $year->id,
            'status'         => 'approved',
            'vp_reviewer_id' => $approver->id,
            'vp_reviewed_at' => '2025-12-17 08:00:00',
            'rated_by_name'  => 'Ailel Rose S. Asequia',
        ]);

        $this->actingAsUser($president);

        $response = $this->get("/api/pcr-forms/{$form->id}/xlsx");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'opcr').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        $merges = $sheet->getMergeCells();

        $this->assertContains('H8:K8', $merges);
        $this->assertContains('H9:K9', $merges);
        $this->assertContains('H10:K10', $merges);
        $this->assertContains('A16:E16', $merges);
        $this->assertContains('A17:E17', $merges);
        $this->assertContains('G12:H12', $merges);
        $this->assertContains('G16:H16', $merges);
        $this->assertNotContains('A12:E12', $merges);

        $this->assertSame('NEILSON D. BATION, DM', $sheet->getCell('H8')->getValue());
        $this->assertSame('Office Head', $sheet->getCell('H9')->getValue());
        $this->assertSame('Date: January to December 2026', $sheet->getCell('H10')->getValue());
        $this->assertSame('Approved by:', $sheet->getCell('A12')->getValue());
        $this->assertSame('ATTY. KENNETH M. KEMPIS', $sheet->getCell('A16')->getValue());
        $this->assertSame('Municipal Administrator', $sheet->getCell('A17')->getValue());
        $this->assertSame('DECEMBER 17, 2025', $sheet->getCell('G16')->getValue());
        $this->assertSame('5 - Outstanding', $sheet->getCell('J13')->getValue());
        $this->assertSame('100%', $sheet->getCell('N13')->getValue());
        $this->assertSame('91-100', $sheet->getCell('O13')->getValue());

        // No body rows, so the sign-off starts on the row after the three totals.
        $this->assertSame('Date', $sheet->getCell('G24')->getValue());
        $this->assertSame('Final Rating by:', $sheet->getCell('H24')->getValue());
        $this->assertSame('Date', $sheet->getCell('M24')->getValue());
        $this->assertSame('Assessed by:', $sheet->getCell('A25')->getValue());
        $this->assertSame('Date', $sheet->getCell('C25')->getValue());
        $this->assertSame('AILEL ROSE S. ASEQUIA', $sheet->getCell('A28')->getValue());
        $this->assertSame('ATTY. KENNETH M. KEMPIS', $sheet->getCell('E28')->getValue());
        $this->assertSame('NEILSON D. BATION, DM', $sheet->getCell('H28')->getValue());
        $this->assertSame("Mun. Planning & Dev't Coordinator", $sheet->getCell('A29')->getValue());
        $this->assertSame('Municipal Administrator - PMT Chairperson', $sheet->getCell('E29')->getValue());
        $this->assertSame('Head of Agency', $sheet->getCell('H29')->getValue());

        foreach ([
            'H24:K24', 'M24:N24', 'A25:B25', 'C25:D25',
            'G25:G29', 'M25:N29', 'C26:D30',
            'A28:B28', 'E28:F28', 'H28:L28',
            'A29:B29', 'E29:F29', 'H29:L29',
        ] as $range) {
            $this->assertContains($range, $merges, $range);
        }

        $this->assertNotContains('H24:L24', $merges);

        unlink($path);
    }
}
