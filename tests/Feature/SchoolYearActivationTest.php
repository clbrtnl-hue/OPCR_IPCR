<?php

namespace Tests\Feature;

use Tests\PmsTestCase;

/**
 * One organization has one current cycle: activating a year (or a review
 * point) must turn the previous year — and its still-flagged period — off.
 */
class SchoolYearActivationTest extends PmsTestCase
{
    public function test_activating_a_year_clears_the_previous_cycle_including_its_period(): void
    {
        $this->makeOrganization();
        $this->actingAsRole('admin');

        $previous = $this->makeSchoolYear(['label' => '2026', 'is_active' => true]);
        $oldPeriod = $this->makePeriod($previous, 1);
        $this->makePeriod($previous, 2, 'upcoming');

        $next = $this->makeSchoolYear([
            'label'      => '2027',
            'start_date' => '2027-01-01',
            'end_date'   => '2027-12-31',
            'is_active'  => false,
        ]);
        $first = $this->makePeriod($next, 1, 'upcoming');
        $this->makePeriod($next, 2, 'upcoming');
        $first->update(['is_active' => false]);

        $this->postJson("/api/school-years/{$next->id}/activate")->assertOk();

        $this->assertFalse((bool) $previous->fresh()->is_active);
        $this->assertFalse((bool) $oldPeriod->fresh()->is_active);
        $this->assertTrue((bool) $next->fresh()->is_active);
        $this->assertTrue((bool) $first->fresh()->is_active);
    }

    public function test_activating_a_period_clears_the_previous_years_period(): void
    {
        $this->makeOrganization();
        $this->actingAsRole('admin');

        $previous = $this->makeSchoolYear(['label' => '2026', 'is_active' => true]);
        $oldPeriod = $this->makePeriod($previous, 1);

        $next = $this->makeSchoolYear([
            'label'      => '2027',
            'start_date' => '2027-01-01',
            'end_date'   => '2027-12-31',
            'is_active'  => false,
        ]);
        $newPeriod = $this->makePeriod($next, 1, 'upcoming');
        $newPeriod->update(['is_active' => false]);

        $this->postJson("/api/rating-periods/{$newPeriod->id}/activate")->assertOk();

        $this->assertFalse((bool) $previous->fresh()->is_active);
        $this->assertFalse((bool) $oldPeriod->fresh()->is_active);
        $this->assertTrue((bool) $next->fresh()->is_active);
        $this->assertTrue((bool) $newPeriod->fresh()->is_active);
        $this->assertSame('open', $newPeriod->fresh()->status);
    }
}
