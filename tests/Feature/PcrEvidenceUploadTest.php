<?php

namespace Tests\Feature;

use App\Models\PcrAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Tests\PmsTestCase;

class PcrEvidenceUploadTest extends PmsTestCase
{
    private function scenario(): array
    {
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $unit   = $this->makeUnit();

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $employee->id,
        ]);

        $indicator = $this->makeIndicator($form);

        Passport::actingAs($employee, [], 'api');

        return compact('period', 'form', 'indicator', 'employee');
    }

    protected function tearDown(): void
    {
        foreach (PcrAttachment::all() as $attachment) {
            $path = public_path('uploads/pcr/' . $attachment->file_path);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_an_image_under_the_limit_is_accepted(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->scenario();

        $response = $this->post('/api/pcr-attachments', [
            'indicator_id'     => $indicator->id,
            'rating_period_id' => $period->id,
            'file'             => UploadedFile::fake()->image('certificate.jpg')->size(2048),
        ]);

        $response->assertStatus(201);
        $this->assertSame('certificate.jpg', $response->json('attachment.original_name'));
        $this->assertDatabaseCount('pcr_attachments', 1);
    }

    public function test_a_file_over_five_megabytes_is_rejected(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->scenario();

        $this->post('/api/pcr-attachments', [
            'indicator_id'     => $indicator->id,
            'rating_period_id' => $period->id,
            'file'             => UploadedFile::fake()->create('report.pdf', 5121, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pcr_attachments', 0);
    }

    public function test_a_file_at_exactly_five_megabytes_is_accepted(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->scenario();

        $this->post('/api/pcr-attachments', [
            'indicator_id'     => $indicator->id,
            'rating_period_id' => $period->id,
            'file'             => UploadedFile::fake()->create('report.pdf', 5120, 'application/pdf'),
        ])->assertStatus(201);
    }

    public function test_an_executable_is_rejected(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->scenario();

        $this->post('/api/pcr-attachments', [
            'indicator_id'     => $indicator->id,
            'rating_period_id' => $period->id,
            'file'             => UploadedFile::fake()->create('payload.exe', 10),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_evidence_cannot_be_added_to_a_closed_period(): void
    {
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year, 2, 'closed');
        $unit   = $this->makeUnit();

        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $form = $this->makeForm([
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
            'user_id'        => $employee->id,
        ]);

        $indicator = $this->makeIndicator($form);

        Passport::actingAs($employee, [], 'api');

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'Published 25 articles.',
        ])->assertStatus(409);
    }

    public function test_a_stranger_cannot_record_accomplishments_on_someone_elses_form(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->scenario();

        Passport::actingAs(User::factory()->create(['role' => 'employee']), [], 'api');

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'Not mine.',
        ])->assertStatus(403);
    }

    public function test_accomplishment_is_saved_once_per_period(): void
    {
        ['period' => $period, 'indicator' => $indicator] = $this->scenario();

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'Published 12 articles.',
        ])->assertOk();

        $this->postJson('/api/pcr-accomplishments', [
            'indicator_id'          => $indicator->id,
            'rating_period_id'      => $period->id,
            'actual_accomplishment' => 'Published 25 articles.',
        ])->assertOk();

        $this->assertDatabaseCount('pcr_accomplishments', 1);
        // Stored as markup now that the field is a rich text editor.
        $this->assertDatabaseHas('pcr_accomplishments', [
            'actual_accomplishment' => '<p>Published 25 articles.</p>',
        ]);
    }
}
