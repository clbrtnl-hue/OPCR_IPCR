<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrStatusLog;
use App\Models\PcrTargetAssignment;
use App\Models\RatingPeriod;
use App\Models\User;
use App\Support\Html;
use Illuminate\Support\Facades\DB;

/**
 * Delegation. An office target is handed to a person; they write their own
 * commitments in their IPCR, anchored to that target. Progress climbs back
 * up as the average of those authored lines.
 *
 * Assigning does not copy the college wording. The assignee sees the target
 * they must answer, then names their own MFO/PPA and success indicators.
 */
class PcrAssignmentService
{
    /**
     * Hand over a heading. The assignee gets the MFO/PPA in their own IPCR and
     * writes their own success indicators under it — the president names what
     * the college is committing to, the head decides how their office delivers it.
     */
    public function assignOutput(PcrOutput $parent, User $assignee, User $actor, ?int $periodId = null): PcrOutput
    {
        return DB::transaction(function () use ($parent, $assignee, $actor, $periodId) {
            $form   = $this->formFor($assignee, $parent->form, $periodId ?? $this->defaultPeriodFor($parent->form));
            $output = $this->outputFor($form, $parent, $actor);

            $this->announce($assignee, $actor, $form, $parent->title);

            ActivityLog::record(
                'PcrOutput',
                $output->id,
                'assign',
                "{$parent->title} assigned to {$assignee->name}"
            );

            return $output;
        });
    }

    /**
     * Make someone accountable for a target. Their IPCR opens (unless they
     * already own the OPCR itself) so they can write commitments against it.
     * Nothing is copied onto their sheet.
     */
    public function assignIndicator(
        PcrIndicator $parent,
        User $assignee,
        User $actor,
        ?int $periodId = null,
        bool $announce = true
    ): PcrTargetAssignment {
        return DB::transaction(function () use ($parent, $assignee, $actor, $periodId, $announce) {
            $parentOutput = $parent->output;
            $period       = $periodId
                ?? $parent->rating_period_id
                ?? $this->defaultPeriodFor($parentOutput->form);

            $keepsTheOpcr = $parentOutput->form->type === 'opcr' && $assignee->role === 'president';

            $form = $keepsTheOpcr
                ? null
                : $this->formFor($assignee, $parentOutput->form, $period);

            $assignment = PcrTargetAssignment::firstOrCreate(
                [
                    'indicator_id'     => $parent->id,
                    'user_id'          => $assignee->id,
                    'rating_period_id' => $period,
                ],
                [
                    'assigned_by'      => $actor->id,
                    'assigned_by_name' => $actor->name,
                ]
            );

            if ($form && $announce && $assignment->wasRecentlyCreated) {
                $this->announce($assignee, $actor, $form, $parent->description);
            }

            if ($assignment->wasRecentlyCreated) {
                ActivityLog::record(
                    'PcrIndicator',
                    $parent->id,
                    'assign',
                    "Assigned to {$assignee->name}: " . mb_substr(Html::toText($parent->description), 0, 120)
                );
            }

            return $assignment;
        });
    }

    private function announce(User $assignee, User $actor, PcrForm $form, string $what): void
    {
        Notification::send(
            $assignee->id,
            'assignment',
            "{$actor->name} assigned you a commitment",
            mb_substr(Html::toText($what), 0, 200) . ' — write your own commitments against it in your IPCR.',
            $form->id
        );
    }

    /**
     * Withdrawing only makes sense while the assignee has not built on it —
     * once there is a commitment or a score against the target, taking it away
     * would destroy someone's record.
     */
    public function withdraw(PcrIndicator $child): bool
    {
        if ($child->accomplishments()->exists() || $child->ratings()->exists()) {
            return false;
        }

        if ($child->children()->exists()) {
            return false;
        }

        $parent = $child->parent;
        $child->delete();

        if ($parent) {
            app(IndicatorProgressService::class)->releaseParent($parent);
        }

        return true;
    }

    public function withdrawAssignment(PcrTargetAssignment $assignment): bool
    {
        $worked = PcrIndicator::where('parent_indicator_id', $assignment->indicator_id)
            ->whereHas('output.form', fn ($q) => $q->where('user_id', $assignment->user_id))
            ->exists();

        if ($worked) {
            return false;
        }

        $assignment->delete();

        return true;
    }

    /**
     * Taking back a heading only makes sense while nothing hangs off it — once
     * the assignee has written commitments under it, that is their work.
     */
    public function withdrawOutput(PcrOutput $output): bool
    {
        if ($output->indicators()->exists() || $output->childOutputs()->exists()) {
            return false;
        }

        $output->delete();

        return true;
    }

    /** The assignee's own IPCR for that period, opened as a draft if they have none. */
    private function formFor(User $assignee, PcrForm $parentForm, ?int $periodId): PcrForm
    {
        $form = PcrForm::firstOrCreate(
            [
                'type'             => 'ipcr',
                'school_year_id'   => $parentForm->school_year_id,
                'user_id'          => $assignee->id,
                'rating_period_id' => $periodId,
            ],
            [
                'org_unit_id' => $assignee->org_unit_id ?? $parentForm->org_unit_id,
                'status'      => 'draft',
            ]
        );

        if ($form->wasRecentlyCreated) {
            PcrStatusLog::record($form->id, null, 'draft', 'Opened by an assignment');
        }

        return $form;
    }

    /** The line above decides the period; a heading falls back to the year's active one. */
    private function defaultPeriodFor(PcrForm $parentForm): ?int
    {
        return RatingPeriod::where('school_year_id', $parentForm->school_year_id)
            ->orderByDesc('is_active')
            ->orderBy('seq')
            ->value('id');
    }

    /** Mirror the heading the work sits under, tied back to it rather than matched by name. */
    private function outputFor(PcrForm $form, PcrOutput $parent, User $actor): PcrOutput
    {
        $existing = PcrOutput::where('form_id', $form->id)
            ->where('parent_output_id', $parent->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return PcrOutput::create([
            'form_id'          => $form->id,
            'parent_output_id' => $parent->id,
            'section'          => $parent->section,
            'title'            => $parent->title,
            'assigned_by'      => $actor->id,
            'assigned_by_name' => $actor->name,
            'sort_order'       => (PcrOutput::where('form_id', $form->id)->max('sort_order') ?? 0) + 1,
        ]);
    }
}
