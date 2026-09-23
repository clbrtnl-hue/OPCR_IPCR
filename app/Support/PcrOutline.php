<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Number an OPCR the way the paper form does:
 * 1. Digitalization
 * 1.1 Entrance Exam
 * 3.1.1 Local
 *
 * A parent_output_id that points outside this set (an IPCR heading answering
 * a college MFO) is treated as a root, not a nested PPA.
 */
class PcrOutline
{
    public static function numbered(iterable $outputs): Collection
    {
        $items = collect($outputs)->values();
        $ids   = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        $children = $items->groupBy(function ($output) use ($ids) {
            $parentId = $output->parent_output_id ? (int) $output->parent_output_id : 0;

            return $parentId && in_array($parentId, $ids, true) ? $parentId : 0;
        });

        $walk = function (int $parentId, string $prefix) use (&$walk, $children) {
            $rows     = collect();
            $siblings = ($children->get($parentId) ?? collect())->sortBy('sort_order')->values();

            foreach ($siblings as $index => $output) {
                $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
                $depth  = $prefix === '' ? 0 : substr_count($prefix, '.') + 1;

                $output->setAttribute('outline_number', $number);
                $output->setAttribute('outline_depth', $depth);

                $rows->push($output);
                $rows = $rows->concat($walk((int) $output->id, $number));
            }

            return $rows;
        };

        return $walk(0, '');
    }

    /** A grouping row: it has nested PPAs on the same form, and may have no SI of its own. */
    public static function isHeader($output): bool
    {
        if ((int) ($output->nested_outputs_count ?? 0) > 0) {
            return true;
        }

        if (! $output->relationLoaded('childOutputs')) {
            return false;
        }

        return $output->childOutputs->contains(
            fn ($child) => (int) $child->form_id === (int) $output->form_id
        );
    }
}
