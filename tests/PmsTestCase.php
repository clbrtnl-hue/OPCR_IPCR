<?php

namespace Tests;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Services\IndicatorProgressService;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\CurrentOrganization;
use Laravel\Passport\Passport;

abstract class PmsTestCase extends TestCase
{
    use RefreshDatabase;

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => $role], $attributes));

        return $this->actingAsUser($user);
    }

    protected function actingAsUser(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        // A request resolves the organization from the signed-in user; tests
        // switch users mid-test, so drop the memoised value with them.
        app(CurrentOrganization::class)->forget();

        return $user;
    }

    protected function makeOrganization(array $attributes = []): Organization
    {
        $organization = Organization::create(array_merge([
            'name'   => 'Opol Community College',
            'code'   => 'occ',
            'status' => 'active',
        ], $attributes));

        app(CurrentOrganization::class)->set($organization->id);

        return $organization;
    }

    protected function makeUnit(array $attributes = []): OrgUnit
    {
        return OrgUnit::create(array_merge([
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'type' => 'program',
        ], $attributes));
    }

    protected function makeSchoolYear(array $attributes = []): SchoolYear
    {
        return SchoolYear::create(array_merge([
            'label'      => 'SY 2026-2027',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-07-31',
            'status'     => 'open',
            'is_active'  => true,
        ], $attributes));
    }

    protected function makePeriod(SchoolYear $year, int $seq = 1, string $status = 'open'): RatingPeriod
    {
        return RatingPeriod::create([
            'school_year_id' => $year->id,
            'seq'            => $seq,
            'label'          => $seq === 1 ? 'Mid-year Review' : 'End-year Review',
            'status'         => $status,
            'is_active'      => $seq === 1,
        ]);
    }

    protected function makeForm(array $attributes = []): PcrForm
    {
        return PcrForm::create(array_merge(['type' => 'ipcr', 'status' => 'draft'], $attributes));
    }

    protected function makeIndicator(PcrForm $form, string $section = 'core', array $attributes = []): PcrIndicator
    {
        $output = PcrOutput::create([
            'form_id' => $form->id,
            'section' => $section,
            'title'   => 'Research',
        ]);

        return PcrIndicator::create(array_merge([
            'output_id'   => $output->id,
            'description' => 'Publish 25 peer-reviewed articles from January to December 2026.',
        ], $attributes));
    }

    /** The ratee writes their own line, anchored to the assigned office target. */
    protected function commitAgainst(PcrIndicator $target, User $user, array $attributes = []): PcrIndicator
    {
        $target->loadMissing('output.form');

        $periodId = $attributes['rating_period_id'] ?? $target->rating_period_id;

        $form = PcrForm::firstOrCreate(
            [
                'type'             => 'ipcr',
                'school_year_id'   => $target->output->form->school_year_id,
                'user_id'          => $user->id,
                'rating_period_id' => $periodId,
            ],
            [
                'org_unit_id' => $user->org_unit_id ?? $target->output->form->org_unit_id,
                'status'      => 'draft',
            ]
        );

        $output = PcrOutput::create([
            'form_id' => $form->id,
            'section' => $target->output->section === 'strategic' ? 'core' : $target->output->section,
            'title'   => $attributes['title'] ?? 'My commitment',
        ]);

        return PcrIndicator::create([
            'output_id'           => $output->id,
            'parent_indicator_id' => $target->id,
            'description'         => $attributes['description'] ?? 'My own success indicator.',
            'rating_period_id'    => $form->rating_period_id ?? $target->rating_period_id,
            'progress_status'     => $attributes['progress_status'] ?? 'not_started',
            'progress_pct'        => $attributes['progress_pct'] ?? 0,
        ]);
    }

    /**
     * A line is finished only by a narrative and a file. Pass $withFile false
     * to leave it written but not counted.
     */
    protected function documentLine(PcrIndicator $line, bool $withFile = true, ?string $text = '<p>Done.</p>'): PcrIndicator
    {
        $line->loadMissing('output.form');

        $periodId = $line->rating_period_id ?: $line->output->form->rating_period_id;

        if (! $periodId) {
            $periodId = RatingPeriod::where('school_year_id', $line->output->form->school_year_id)->value('id');
        }

        $record = PcrAccomplishment::updateOrCreate(
            ['indicator_id' => $line->id, 'rating_period_id' => $periodId],
            ['actual_accomplishment' => $text]
        );

        if ($withFile && ! $record->attachments()->exists()) {
            PcrAttachment::create([
                'accomplishment_id' => $record->id,
                'file_path'         => 'evidence-test.png',
                'original_name'     => 'evidence.png',
                'mime'              => 'image/png',
                'file_size'         => 100,
            ]);
        }

        app(IndicatorProgressService::class)->syncFromRecord($line->fresh());

        return $line->fresh();
    }
}
