<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * A unit can show someone on the chart who is not its head. That person
 * does not join the review chain and does not see the unit in My Teams.
 */
class ChartDisplayTest extends PmsTestCase
{
    public function test_a_person_shown_on_the_chart_does_not_review(): void
    {
        $this->makeOrganization();

        $office = $this->makeUnit(['name' => 'Student Development Service', 'code' => 'SDS']);
        $year   = $this->makeSchoolYear();
        $this->makePeriod($year);

        $head    = User::factory()->create(['role' => 'program_head', 'name' => 'Head Nacua']);
        $shown    = User::factory()->create(['role' => 'employee', 'name' => 'Fabian Singuil', 'position_title' => 'Adviser']);
        $second   = User::factory()->create(['role' => 'employee', 'name' => 'College Secretary']);
        $staff   = User::factory()->create(['role' => 'employee', 'org_unit_id' => $office->id]);
        $admin   = User::factory()->create(['role' => 'admin']);

        $office->update(['head_user_id' => $head->id]);

        $this->actingAsUser($admin);
        $this->postJson('/api/org-units', [
            'id'            => $office->id,
            'name'          => $office->name,
            'code'          => $office->code,
            'type'          => 'office',
            'head_user_id'   => $head->id,
            'chart_user_ids' => [$shown->id, $second->id],
        ])->assertOk();

        $row = collect($this->getJson('/api/org-units')->assertOk()->json())
            ->firstWhere('id', $office->id);
        $card = collect($row['reviewers'])->firstWhere('slot', 'chart_display_1');
        $this->assertSame($shown->id, $card['user_id']);
        $this->assertSame('Fabian Singuil', $card['user']['name']);
        $other = collect($row['reviewers'])->firstWhere('slot', 'chart_display_2');
        $this->assertSame($second->id, $other['user_id']);

        $this->actingAsUser($shown);
        $this->assertSame([], $this->getJson('/api/org-units/leading')->assertOk()->json());
        $this->actingAsUser($second);
        $this->assertSame([], $this->getJson('/api/org-units/leading')->assertOk()->json());

        $president = User::factory()->create(['role' => 'president', 'name' => 'President']);
        $this->makeForm([
            'type' => 'opcr', 'org_unit_id' => $office->id, 'school_year_id' => $year->id,
            'status' => 'published', 'user_id' => $president->id,
        ]);

        $form = $this->makeForm([
            'org_unit_id' => $office->id, 'school_year_id' => $year->id, 'user_id' => $staff->id,
        ]);
        $this->makeIndicator($form, 'support');

        $this->actingAsUser($staff);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'commitment_review'])->assertOk();
        $this->assertSame($head->id, (int) $form->fresh()->commitment_approver_id);

        $this->approveCommitments($form, $head);
        $this->documentLine($form->indicators()->first());
        $this->actingAsUser($staff);
        $this->postJson("/api/pcr-forms/{$form->id}/status", ['status' => 'head_review'])->assertOk();

        $fresh = $form->fresh();
        $this->assertSame('head_review', $fresh->status);
        $this->assertSame($head->id, (int) $fresh->head_reviewer_id);

        $this->actingAsUser($admin);
        $this->postJson('/api/org-units', [
            'id'            => $office->id,
            'name'          => $office->name,
            'code'          => $office->code,
            'type'          => 'office',
            'head_user_id'   => $head->id,
            'chart_user_ids' => [],
        ])->assertOk();

        $cleared = collect($this->getJson('/api/org-units')->assertOk()->json())
            ->firstWhere('id', $office->id);
        $empty = collect($cleared['reviewers'])->firstWhere('slot', 'chart_display_1');
        $this->assertNull($empty);
        $this->assertNull(collect($cleared['reviewers'])->firstWhere('slot', 'chart_display_2'));
    }
}
