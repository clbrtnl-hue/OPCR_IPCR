<?php

namespace App\Services;

use App\Models\PcrIndicator;
use App\Models\PcrOutput;
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
    /**
     * A leaf is finished only when the writer has both a narrative and a file.
     * Anything short of that stays at 0, and the lines above it are averaged again.
     * A line that was handed on is not the writer's own number — it follows the
     * people they named.
     */
    public function syncFromRecord(PcrIndicator $line): void
    {
        $line->loadMissing('output.form');

        if ($line->children()->exists() || $line->assignments()->exists()) {
            $child = $line->children()->first();

            if ($child) {
                $this->recalculateFrom($child);
            }

            return;
        }

        $records = $line->accomplishments()->withCount('attachments')->get();

        if ($line->rating_period_id) {
            $records = $records->where('rating_period_id', $line->rating_period_id);
        }

        $done = $records->contains(
            fn ($record) => ! Html::isBlank($record->actual_accomplishment) && (int) $record->attachments_count > 0
        );

        $started = $records->contains(
            fn ($record) => ! Html::isBlank($record->actual_accomplishment) || (int) $record->attachments_count > 0
        );

        $line->forceFill([
            'progress_pct'    => $done ? 100 : 0,
            'progress_status' => $done ? 'completed' : ($started ? 'ongoing' : 'not_started'),
            'completed_on'    => $done ? ($line->completed_on ?? now()->toDateString()) : null,
        ])->save();

        $this->recalculateFrom($line);
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
            if ($target->children()->exists()) {
                continue;   // measured by its own sub-tasks instead
            }

            $target->forceFill([
                'progress_pct'    => $average,
                'progress_status' => $status,
            ])->save();
        }
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
