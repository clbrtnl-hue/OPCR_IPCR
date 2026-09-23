<?php

namespace Tests\Feature;

use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\PmsTestCase;

class SpreadsheetExportTest extends PmsTestCase
{
    private function cycle(): array
    {
        $this->makeOrganization();

        $unit    = $this->makeUnit();
        $year    = $this->makeSchoolYear();
        $period  = $this->makePeriod($year, 1);
        $faculty = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id, 'name' => 'Juan Dela Cruz']);

        $form = $this->makeForm([
            'org_unit_id'      => $unit->id,
            'school_year_id'   => $year->id,
            'user_id'          => $faculty->id,
            'rating_period_id' => $period->id,
            'status'           => 'head_review',
        ]);

        $this->makeIndicator($form, 'core', [
            'rating_period_id' => $period->id,
            'description'      => '<p>Publish <strong>25</strong> peer-reviewed articles.</p>',
            'progress_pct'     => 40,
        ]);

        return compact('unit', 'year', 'period', 'faculty', 'form');
    }

    private function download(string $query)
    {
        $response = $this->get("/api/reports/summary/export?{$query}");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pms') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        return $path;
    }

    public function test_the_workbook_opens_and_carries_the_rows(): void
    {
        ['year' => $year, 'faculty' => $faculty] = $this->cycle();

        $this->actingAsRole('qa');

        $path  = $this->download("school_year_id={$year->id}&table=people&format=xlsx");
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('By individual', $sheet->getTitle());
        $this->assertSame('Name', $sheet->getCell('A1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        $names = collect($sheet->toArray())->skip(1)->pluck(0);

        $this->assertTrue($names->contains($faculty->name));

        unlink($path);
    }

    public function test_numbers_are_written_as_numbers_and_markup_as_words(): void
    {
        ['year' => $year] = $this->cycle();

        $this->actingAsRole('qa');

        $path  = $this->download("school_year_id={$year->id}&table=commitments&format=xlsx");
        $sheet = IOFactory::load($path)->getActiveSheet();

        $rows = collect($sheet->toArray(null, true, false))->skip(1)->values();

        $this->assertNotEmpty($rows);

        $description = $rows[0][3];
        $progress    = $rows[0][5];

        $this->assertStringNotContainsString('<', (string) $description);
        $this->assertStringContainsString('Publish', (string) $description);
        $this->assertIsFloat($progress);
        $this->assertSame(40.0, $progress);

        unlink($path);
    }

    public function test_a_formula_cannot_be_smuggled_into_a_cell(): void
    {
        ['year' => $year, 'form' => $form] = $this->cycle();

        $this->makeIndicator($form, 'support', [
            'rating_period_id' => $form->rating_period_id,
            'description'      => '=1+1',
        ]);

        $this->actingAsRole('qa');

        $path  = $this->download("school_year_id={$year->id}&table=commitments&format=xlsx");
        $sheet = IOFactory::load($path)->getActiveSheet();

        $descriptions = collect($sheet->toArray())->skip(1)->pluck(3);

        $this->assertTrue($descriptions->contains('=1+1'), 'The text should survive verbatim, not be evaluated.');

        unlink($path);
    }

    public function test_csv_is_still_the_default(): void
    {
        ['year' => $year] = $this->cycle();

        $this->actingAsRole('qa');

        $this->get("/api/reports/summary/export?school_year_id={$year->id}&table=people")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_an_unknown_format_is_refused(): void
    {
        ['year' => $year] = $this->cycle();

        $this->actingAsRole('qa');

        $this->getJson("/api/reports/summary/export?school_year_id={$year->id}&table=people&format=pdf")
            ->assertStatus(422);
    }

    public function test_an_employee_cannot_take_the_workbook(): void
    {
        ['year' => $year, 'faculty' => $faculty] = $this->cycle();

        $this->actingAsUser($faculty);

        $this->getJson("/api/reports/summary/export?school_year_id={$year->id}&table=people&format=xlsx")
            ->assertStatus(403);
    }
}
