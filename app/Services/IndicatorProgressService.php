<?php

namespace App\Services;

use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrTargetAssignment;
use App\Support\Html;

/**
 * A delegated commitment is only as far along as the work under it. When a leaf
 * moves, every line above it is recomputed as the average of its children, up to
 * the office target — so an OPCR line reads 50% while one of two heads has
 * finished, and 100% only once both have.
 *
 * A line with children is therefore computed, never typed in by hand.
 */
class IndicatorProgressService
{
    /** The written actual accomplishment is this share of a leaf's progress. */
    public const NARRATIVE_WEIGHT = 30;

    /** An attached file is this share. Both together are 100. */
    public const EVIDENCE_WEIGHT = 70;

    /**
     * A leaf's percent is the narrative (30) plus the evidence file (70).
     * Either piece counts on its own, and the line is finished only once both
     * are there. A line that was handed on is not the writer's own number —
     * it follows the people they named.
     */
    public function syncFromRecord(PcrIndicator $line): void
    {
        $line->loadMissing('output.form');

        if ($line->children()->exists() || $line->assignments()->exists()) {
            $child = $line->children()->first();

            if ($child) {
                $this->recalculateFrom($child);
            }

            $this->shareAcrossAssignedTargets($line->output?->form);

            return;
        }

        $records = $line->accomplishments()->withCount('attachments')->get();

        if ($line->rating_period_id) {
            $records = $records->where('rating_period_id', $line->rating_period_id);
        }

        $pct     = $this->percentFor($records);
        $done    = $pct >= 100;
        $started = $pct > 0;

        $line->forceFill([
            'progress_pct'    => $pct,
            'progress_status' => $done ? 'completed' : ($started ? 'ongoing' : 'not_started'),
            'completed_on'    => $done ? ($line->completed_on ?? now()->toDateString()) : null,
        ])->save();

        $this->recalculateFrom($line);
        $this->shareAcrossAssignedTargets($line->output?->form);
    }

    public function recalculateFrom(PcrIndicator $line): void
    {
        $current = $line->parent;
        $seen    = [];

        while ($current && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;

            $children = $current->children()->get(['progress_status', 'progress_pct']);

            if ($children->isEmpty()) {
                break;
            }

            $current->forceFill([
                'progress_pct'    => (int) round($children->avg('progress_pct')),
                'progress_status' => $this->statusFor($children),
            ])->save();

            // The parent may sit under an office heading even when the leaf's
            // own output does not, so the office target has to be remeasured here.
            $this->climbHeadings($current);

            $current = $current->parent;
        }

        $this->climbHeadings($line);
        $this->shareAcrossAssignedTargets($line->output?->form);
    }

    public function releaseParent(PcrIndicator $parent): void
    {
        $sibling = $parent->children()->first();

        if ($sibling) {
            $this->recalculateFrom($sibling);

            return;
        }

        $parent->forceFill(['progress_pct' => 0, 'progress_status' => 'not_started'])->save();
        $this->recalculateFrom($parent);
    }

    /**
     * Work delegated as a whole MFO/PPA has no line above it — the head writes
     * their own commitments under the heading they were given. So an office
     * target is measured by the commitments made directly beneath its heading,
     * which is what makes "Research" sit at 0% until the assigned heads finish.
     *
     * Only office targets are computed this way. A head's own lines are their
     * own work: whatever they did not hand out stays theirs to report on.
     */
    private function climbHeadings(PcrIndicator $line): void
    {
        $output = $line->output?->parentOutput;
        $seen   = [];

        while ($output && ! isset($seen[$output->id])) {
            $seen[$output->id] = true;

            if ($output->form?->type === 'opcr') {
                $this->measureOfficeTargets($output);
            }

            $output = $output->parentOutput;
        }
    }

    private function measureOfficeTargets(PcrOutput $office): void
    {
        // The commitments made one level down — each already reflects anything
        // delegated beneath it, so counting the whole subtree would double up.
        $childOutputIds = $office->childOutputs()->pluck('id');

        if ($childOutputIds->isEmpty()) {
            return;
        }

        $beneath = PcrIndicator::whereIn('output_id', $childOutputIds)
            ->get(['progress_status', 'progress_pct']);

        if ($beneath->isEmpty()) {
            return;
        }

        $average = (int) round($beneath->avg('progress_pct'));
        $status  = $this->statusFor($beneath);

        foreach ($office->indicators()->get() as $target) {
            if ($target->children()->exists() || $target->assignments()->exists()) {
                continue;   // measured by its own sub-tasks, or by the assignee's commitments
            }

            $target->forceFill([
                'progress_pct'    => $average,
                'progress_status' => $status,
            ])->save();
        }
    }

    /**
     * Refresh every target this person was assigned. A target moves only with
     * the commitments linked to it. An assigned target with no link is left
     * without a percent, so the screen can show N/A instead of 0 or the
     * person's overall IPCR average.
     */
    public function shareAcrossAssignedTargets(?PcrForm $form, array &$seen = []): void
    {
        if (! $form || $form->type !== 'ipcr' || ! $form->user_id) {
            return;
        }

        $assignments = PcrTargetAssignment::with('indicator.output.form')
            ->where('user_id', $form->user_id)
            ->when($form->rating_period_id, function ($query) use ($form) {
                $query->where(function ($inner) use ($form) {
                    $inner->whereNull('rating_period_id')
                        ->orWhere('rating_period_id', $form->rating_period_id);
                });
            })
            ->get();

        foreach ($assignments as $assignment) {
            $this->paintTarget($assignment->indicator, $seen);
        }
    }

    private function paintTarget(?PcrIndicator $target, array &$seen): void
    {
        if (! $target || isset($seen[$target->id])) {
            return;
        }

        $seen[$target->id] = true;
        $target->loadMissing('output.form');

        $children = $target->children()->get(['progress_status', 'progress_pct']);

        if ($children->isEmpty()) {
            if ($target->assignments()->exists()) {
                $target->forceFill([
                    'progress_pct'    => 0,
                    'progress_status' => 'not_started',
                ])->save();
            }

            return;
        }

        $target->forceFill([
            'progress_pct'    => (int) round($children->avg('progress_pct')),
            'progress_status' => $this->statusFor($children),
        ])->save();

        $ownerForm = $target->output?->form;

        if ($ownerForm?->type === 'ipcr') {
            $this->shareAcrossAssignedTargets($ownerForm, $seen);
        }
    }

    /**
     * The furthest a line has got across its records. A narrative is 30 and a
     * file is 70, so one of each on the same record is 100.
     */
    private function percentFor($records): int
    {
        $best = 0;

        foreach ($records as $record) {
            $score = 0;

            if (! Html::isBlank($record->actual_accomplishment)) {
                $score += self::NARRATIVE_WEIGHT;
            }

            if ((int) $record->attachments_count > 0) {
                $score += self::EVIDENCE_WEIGHT;
            }

            $best = max($best, $score);
        }

        return $best;
    }

    private function statusFor($children): string
    {
        if ($children->every(fn ($c) => $c->progress_status === 'completed')) {
            return 'completed';
        }

        $moved = $children->contains(
            fn ($c) => $c->progress_status !== 'not_started' || (int) $c->progress_pct > 0
        );

        return $moved ? 'ongoing' : 'not_started';
    }
}
