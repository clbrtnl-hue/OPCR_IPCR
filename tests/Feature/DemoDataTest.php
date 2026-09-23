<?php

namespace Tests\Feature;

use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\Notification;
use App\Models\SchoolYear;
use App\Services\RatingScale;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoYear2025Seeder;
use Tests\PmsTestCase;

class DemoDataTest extends PmsTestCase
{
    private array $filesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesBefore = glob(public_path('uploads/pcr/demo2025-*')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(public_path('uploads/pcr/demo2025-*')) ?: [], $this->filesBefore) as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function demo(): SchoolYear
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoYear2025Seeder::class);

        return SchoolYear::where('label', '2025')->firstOrFail();
    }

    public function test_the_demo_year_is_full_and_leaves_the_active_cycle_alone(): void
    {
        $year = $this->demo();

        $this->assertFalse((bool) $year->is_active);
        $this->assertSame('2026', SchoolYear::where('is_active', true)->value('label'));

        $forms = PcrForm::where('school_year_id', $year->id)->get();

        $this->assertGreaterThan(20, $forms->count());
        $this->assertSame(1, $forms->where('type', 'opcr')->count());
        $this->assertGreaterThan(4, $forms->pluck('status')->unique()->count(), 'The pipeline should have work at several stages.');

        $lines = PcrIndicator::whereHas('output.form', fn ($q) => $q->where('school_year_id', $year->id))->get();

        $this->assertGreaterThan(100, $lines->count());
        $this->assertGreaterThan(0, $lines->where('progress_status', 'completed')->count());
        $this->assertGreaterThan(0, $lines->where('progress_status', 'ongoing')->count());
        $this->assertGreaterThan(
            0,
            $lines->filter(fn ($line) => $line->delay['state'] === 'late' || $line->delay['state'] === 'overdue')->count(),
            'Some commitments should be running late.'
        );
    }

    public function test_every_rated_form_adds_up(): void
    {
        $year = $this->demo();

        $rated = PcrForm::where('school_year_id', $year->id)
            ->whereIn('status', ['rated', 'final'])
            ->get();

        $this->assertGreaterThan(5, $rated->count());

        foreach ($rated as $form) {
            $lineIds = PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))->pluck('id');
            $ratings = PcrRating::whereIn('indicator_id', $lineIds)->get();
            $summary = PcrPeriodSummary::where('form_id', $form->id)->firstOrFail();

            $this->assertCount($lineIds->count(), $ratings, "Form {$form->id} has an unrated line.");
            $this->assertSame($lineIds->count(), (int) $summary->total_indicators);
            $this->assertNotNull($form->rated_at);
            $this->assertTrue($form->submitted_at->lte($form->rated_at));

            $recomputed = RatingScale::mean(array_values(array_filter([
                $summary->strategic_average,
                $summary->core_average,
                $summary->support_average,
            ], fn ($value) => $value !== null)));

            $this->assertEqualsWithDelta($recomputed, (float) $summary->final_average, 0.01);
            $this->assertSame(RatingScale::adjectival($recomputed), $summary->adjectival);
        }
    }

    public function test_the_demo_is_re_runnable_and_quiet(): void
    {
        $year  = $this->demo();
        $forms = PcrForm::where('school_year_id', $year->id)->count();

        $notifications = Notification::whereIn('form_id', PcrForm::where('school_year_id', $year->id)->pluck('id'));

        $this->assertGreaterThan(0, $notifications->count(), 'The year should leave a notification history.');
        $this->assertSame(
            0,
            $notifications->clone()->whereNull('read_at')->count(),
            'A historical year should not light up anybody\'s bell.'
        );

        $this->seed(DemoYear2025Seeder::class);

        $again = SchoolYear::where('label', '2025')->get();

        $this->assertCount(1, $again);
        $this->assertSame($forms, PcrForm::where('school_year_id', $again->first()->id)->count());
    }

    public function test_the_demo_carries_remarks_evidence_and_a_paper_trail(): void
    {
        $year    = $this->demo();
        $formIds = PcrForm::where('school_year_id', $year->id)->pluck('id');

        $this->assertGreaterThan(
            0,
            \App\Models\PcrComment::whereIn('form_id', $formIds)->count(),
            'Forms should carry remarks.'
        );

        $accomplishments = \App\Models\PcrAccomplishment::whereHas(
            'indicator.output.form',
            fn ($q) => $q->where('school_year_id', $year->id)
        )->pluck('id');

        $this->assertGreaterThan(0, $accomplishments->count());

        $files = \App\Models\PcrAttachment::whereIn('accomplishment_id', $accomplishments)->get();

        $this->assertGreaterThan(0, $files->count(), 'Delivered work should carry evidence.');
        $this->assertTrue(
            file_exists(public_path('uploads/pcr/' . $files->first()->file_path)),
            'The evidence file should exist on disk.'
        );

        $this->assertGreaterThan(
            0,
            PcrRating::whereNotNull('remarks')->count(),
            'QA should have left remarks on the ratings.'
        );

        $this->assertGreaterThan(
            0,
            \App\Models\ActivityLog::whereBetween('created_at', ['2025-01-01', '2026-02-01'])->count(),
            'The year should leave an audit trail.'
        );

        $this->assertGreaterThan(0, \App\Models\UserProfile::count(), 'People should have a data sheet.');
    }
}
