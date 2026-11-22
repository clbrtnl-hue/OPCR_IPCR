<?php

namespace Tests\Feature;

use App\Models\PcrFormReviewer;
use App\Models\User;
use Tests\PmsTestCase;

class RatingTargetsTest extends PmsTestCase
{
    public function test_linked_commitments_group_under_the_target_the_rater_can_score(): void
    {
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $unit   = $this->makeUnit();
        $qa     = User::factory()->create(['role' => 'qa']);
        $head   = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $maria  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ruben  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'status'         => 'published',
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['description' => 'Publish 25 articles.']);

        $mariaForm = $this->makeForm([
            'status'           => 'qa_rating',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $maria->id,
            'rating_period_id' => $period->id,
        ]);
        $mariaLine = $this->makeIndicator($mariaForm, 'core', [
            'parent_indicator_id' => $target->id,
            'description'         => 'Maria writes eight articles.',
        ]);

        $waiting = $this->makeForm([
            'status'           => 'head_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $ruben->id,
            'rating_period_id' => $period->id,
        ]);
        $rubenLine = $this->makeIndicator($waiting, 'core', [
            'parent_indicator_id' => $target->id,
            'description'         => 'Ruben writes four articles.',
        ]);
        PcrFormReviewer::sync($waiting, ['head_review' => $head->id]);

        $childOffice = $this->makeForm([
            'type'           => 'opcr',
            'status'         => 'qa_rating',
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ]);
        $childLine = $this->makeIndicator($childOffice, 'core', [
            'parent_indicator_id' => $target->id,
            'description'         => 'The office publishes the articles.',
        ]);

        $closed = $this->makeForm([
            'status'           => 'final',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id])->id,
            'rating_period_id' => $period->id,
        ]);
        $closedLine = $this->makeIndicator($closed, 'core', [
            'parent_indicator_id' => $target->id,
            'description'         => 'Already closed.',
        ]);

        $this->actingAsUser($qa);

        $groups = $this->getJson('/api/pcr-ratings/targets')->assertOk()->json();

        $this->assertCount(1, $groups);
        $this->assertSame($target->id, $groups[0]['id']);
        $this->assertSame('opcr', $groups[0]['kind']);
        $forQa = collect($groups[0]['lines'])->keyBy('id');
        $this->assertEqualsCanonicalizing(
            [$mariaLine->id, $rubenLine->id, $childLine->id],
            $forQa->keys()->all()
        );
        $this->assertNotContains($closedLine->id, $forQa->keys()->all());
        $this->assertTrue($forQa[$mariaLine->id]['can_score']);
        $this->assertFalse($forQa[$rubenLine->id]['can_score']);
        $this->assertTrue($forQa[$childLine->id]['can_score']);

        $this->actingAsUser($head);

        $this->assertSame([], $this->getJson('/api/pcr-ratings/targets')->assertOk()->json());
    }

    public function test_a_head_sees_the_cascade_under_his_commitment_including_the_next_link(): void
    {
        $year     = $this->makeSchoolYear();
        $period   = $this->makePeriod($year);
        $unit     = $this->makeUnit();
        $vp       = User::factory()->create(['role' => 'vp', 'org_unit_id' => $unit->id]);
        $ruben    = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $juan     = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $employee = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $other    = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);

        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'status'         => 'published',
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ]);
        $college = $this->makeIndicator($opcr, 'core', ['description' => 'Publish 25 articles.']);

        $vpForm = $this->makeForm([
            'status'           => 'qa_rating',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $vp->id,
            'rating_period_id' => $period->id,
        ]);
        $vpLine = $this->makeIndicator($vpForm, 'core', [
            'parent_indicator_id' => $college->id,
            'description'         => 'The VP commits the articles.',
        ]);

        $rubenForm = $this->makeForm([
            'status'           => 'vp_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $ruben->id,
            'rating_period_id' => $period->id,
        ]);
        $rubenLine = $this->makeIndicator($rubenForm, 'core', [
            'parent_indicator_id' => $vpLine->id,
            'description'         => 'Ruben commits eight articles.',
        ]);

        $juanForm = $this->makeForm([
            'status'           => 'head_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $juan->id,
            'rating_period_id' => $period->id,
        ]);
        $juanLine = $this->makeIndicator($juanForm, 'core', [
            'parent_indicator_id' => $rubenLine->id,
            'description'         => 'Juan commits four articles.',
        ]);
        PcrFormReviewer::sync($juanForm, ['head_review' => $ruben->id]);

        $employeeForm = $this->makeForm([
            'status'           => 'draft',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $employee->id,
            'rating_period_id' => $period->id,
        ]);
        $employeeLine = $this->makeIndicator($employeeForm, 'core', [
            'parent_indicator_id' => $juanLine->id,
            'description'         => 'The employee writes two articles.',
        ]);

        $strangerForm = $this->makeForm([
            'status'           => 'head_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $other->id,
            'rating_period_id' => $period->id,
        ]);
        $strangerLine = $this->makeIndicator($strangerForm, 'core', [
            'parent_indicator_id' => $college->id,
            'description'         => 'Linked only to the college target.',
        ]);

        $this->actingAsUser($ruben);

        $groups = collect($this->getJson('/api/pcr-ratings/targets')->assertOk()->json())->keyBy('id');
        $shown  = $groups->flatMap(fn ($group) => collect($group['lines'])->pluck('id'));

        $this->assertEqualsCanonicalizing([$rubenLine->id, $juanLine->id], $groups->keys()->all());
        $this->assertSame('program_head', $groups[$rubenLine->id]['kind']);
        $this->assertEqualsCanonicalizing(
            [$juanLine->id, $employeeLine->id],
            collect($groups[$rubenLine->id]['lines'])->pluck('id')->all()
        );
        $this->assertTrue(collect($groups[$rubenLine->id]['lines'])->firstWhere('id', $juanLine->id)['can_score']);
        $this->assertFalse(collect($groups[$rubenLine->id]['lines'])->firstWhere('id', $employeeLine->id)['can_score']);
        $this->assertEqualsCanonicalizing(
            [$employeeLine->id],
            collect($groups[$juanLine->id]['lines'])->pluck('id')->all()
        );
        $this->assertNotContains($rubenLine->id, $shown->all());
        $this->assertNotContains($strangerLine->id, $shown->all());
        $this->assertArrayNotHasKey($college->id, $groups->all());
        $this->assertArrayNotHasKey($vpLine->id, $groups->all());

        $this->actingAsUser($vp);

        $forVp = collect($this->getJson('/api/pcr-ratings/targets')->assertOk()->json())->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$rubenLine->id, $juanLine->id, $employeeLine->id],
            collect($forVp[$vpLine->id]['lines'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$juanLine->id, $employeeLine->id],
            collect($forVp[$rubenLine->id]['lines'])->pluck('id')->all()
        );
        $this->assertNotContains(
            $vpLine->id,
            $forVp->flatMap(fn ($group) => collect($group['lines'])->pluck('id'))->all()
        );
    }
}
