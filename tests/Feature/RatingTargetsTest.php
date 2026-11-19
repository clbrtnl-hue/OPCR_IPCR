<?php

namespace Tests\Feature;

use App\Models\OrgUnitReviewer;
use App\Models\PcrFormReviewer;
use App\Models\PcrTargetAssignment;
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

    public function test_a_head_or_vp_sees_their_own_ipcr_and_the_targets_they_cascaded(): void
    {
        $year   = $this->makeSchoolYear();
        $period = $this->makePeriod($year);
        $unit   = $this->makeUnit();
        $other  = $this->makeUnit(['name' => 'Other office', 'code' => 'OTH']);
        $head   = User::factory()->create(['role' => 'program_head', 'org_unit_id' => $unit->id]);
        $vp     = User::factory()->create(['role' => 'vp', 'org_unit_id' => $unit->id]);
        $maria  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $ruben  = User::factory()->create(['role' => 'employee', 'org_unit_id' => $unit->id]);
        $far    = User::factory()->create(['role' => 'employee', 'org_unit_id' => $other->id]);

        OrgUnitReviewer::create([
            'org_unit_id' => $unit->id,
            'slot'        => 'head_user_id',
            'user_id'     => $head->id,
        ]);

        $opcr = $this->makeForm([
            'type'           => 'opcr',
            'status'         => 'published',
            'school_year_id' => $year->id,
            'org_unit_id'    => $unit->id,
        ]);
        $target = $this->makeIndicator($opcr, 'core', ['description' => 'Publish 25 articles.']);
        $handed = $this->makeIndicator($opcr, 'core', ['description' => 'Host four seminars.']);

        $headForm = $this->makeForm([
            'status'           => 'vp_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $head->id,
            'rating_period_id' => $period->id,
        ]);
        $headLine = $this->makeIndicator($headForm, 'core', [
            'parent_indicator_id' => $target->id,
            'description'         => 'The head commits eight articles.',
        ]);

        $underHead = $this->makeForm([
            'status'           => 'head_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $other->id,
            'user_id'          => $far->id,
            'rating_period_id' => $period->id,
        ]);
        $underHeadLine = $this->makeIndicator($underHead, 'core', [
            'parent_indicator_id' => $headLine->id,
            'description'         => 'Far writes against the head line.',
        ]);

        PcrTargetAssignment::create([
            'indicator_id'     => $handed->id,
            'user_id'          => $maria->id,
            'assigned_by'      => $head->id,
            'assigned_by_name' => $head->name,
            'rating_period_id' => $period->id,
        ]);
        $mariaForm = $this->makeForm([
            'status'           => 'head_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $maria->id,
            'rating_period_id' => $period->id,
        ]);
        $mariaLine = $this->makeIndicator($mariaForm, 'core', [
            'parent_indicator_id' => $handed->id,
            'description'         => 'Maria hosts two seminars.',
        ]);
        PcrFormReviewer::sync($mariaForm, ['head_review' => $head->id]);

        PcrTargetAssignment::create([
            'indicator_id'     => $target->id,
            'user_id'          => $ruben->id,
            'assigned_by'      => $vp->id,
            'assigned_by_name' => $vp->name,
            'rating_period_id' => $period->id,
        ]);
        $rubenForm = $this->makeForm([
            'status'           => 'head_review',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $unit->id,
            'user_id'          => $ruben->id,
            'rating_period_id' => $period->id,
        ]);
        $rubenLine = $this->makeIndicator($rubenForm, 'core', [
            'parent_indicator_id' => $target->id,
            'description'         => 'Ruben writes against the same college target.',
        ]);
        PcrFormReviewer::sync($rubenForm, ['head_review' => $head->id]);

        $outsider = User::factory()->create(['role' => 'employee', 'org_unit_id' => $other->id]);
        $outside  = $this->makeIndicator($opcr, 'core', ['description' => 'A target handed outside this office.']);
        PcrTargetAssignment::create([
            'indicator_id'     => $outside->id,
            'user_id'          => $outsider->id,
            'assigned_by'      => $head->id,
            'assigned_by_name' => $head->name,
            'rating_period_id' => $period->id,
        ]);
        $hidden = $this->makeForm([
            'status'           => 'draft',
            'school_year_id'   => $year->id,
            'org_unit_id'      => $other->id,
            'user_id'          => $outsider->id,
            'rating_period_id' => $period->id,
        ]);
        $hiddenLine = $this->makeIndicator($hidden, 'core', [
            'parent_indicator_id' => $outside->id,
            'description'         => 'Outside the offices this head can read.',
        ]);

        $this->actingAsUser($head);

        $groups = collect($this->getJson('/api/pcr-ratings/targets')->assertOk()->json())->keyBy('id');

        $this->assertSame('opcr', $groups[$target->id]['kind']);
        $this->assertSame('program_head', $groups[$headLine->id]['kind']);
        $this->assertEqualsCanonicalizing(
            [$headLine->id],
            collect($groups[$target->id]['lines'])->pluck('id')->all()
        );
        $this->assertFalse(collect($groups[$target->id]['lines'])->firstWhere('id', $headLine->id)['can_score']);
        $this->assertEqualsCanonicalizing(
            [$underHeadLine->id],
            collect($groups[$headLine->id]['lines'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$mariaLine->id],
            collect($groups[$handed->id]['lines'])->pluck('id')->all()
        );
        $this->assertTrue($groups[$handed->id]['lines'][0]['can_score']);
        $this->assertNotContains($rubenLine->id, $groups->flatMap(fn ($group) => collect($group['lines'])->pluck('id'))->all());
        $this->assertNotContains($hiddenLine->id, $groups->flatMap(fn ($group) => collect($group['lines'])->pluck('id'))->all());

        OrgUnitReviewer::create([
            'org_unit_id' => $unit->id,
            'slot'        => 'vp_user_id',
            'user_id'     => $vp->id,
        ]);

        $this->actingAsUser($vp);

        $forVp = collect($this->getJson('/api/pcr-ratings/targets')->assertOk()->json())->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$rubenLine->id],
            collect($forVp[$target->id]['lines'])->pluck('id')->all()
        );
        $this->assertArrayNotHasKey($handed->id, $forVp->all());
        $this->assertArrayNotHasKey($headLine->id, $forVp->all());
    }
}
