<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dean and Director are different posts on the org chart. The combined
 * "Dean / Director" stage becomes Dean, and a skippable Director stage sits
 * after it. People whose title is Director move off the dean slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        $director = [
            'status'    => 'director_review',
            'label'     => 'With Director',
            'source'    => 'unit_slot',
            'slot'      => 'director_review',
            'skippable' => true,
        ];

        foreach (DB::table('workflow_settings')->where('key', 'review_stages')->get() as $row) {
            $stages = is_string($row->value) ? json_decode($row->value, true) : $row->value;

            if (! is_array($stages)) {
                continue;
            }

            DB::table('workflow_settings')->where('id', $row->id)->update([
                'value' => json_encode(array_values($this->patchStages($stages, $director))),
            ]);
        }

        $this->moveDirectorsOffDeanSlot();
    }

    public function down(): void
    {
        foreach (DB::table('workflow_settings')->where('key', 'review_stages')->get() as $row) {
            $stages = is_string($row->value) ? json_decode($row->value, true) : $row->value;

            if (! is_array($stages)) {
                continue;
            }

            $next = [];

            foreach ($stages as $stage) {
                if (($stage['status'] ?? '') === 'director_review') {
                    continue;
                }

                if (($stage['status'] ?? '') === 'dean_review') {
                    $stage['label'] = 'With Dean / Director';
                }

                $next[] = $stage;
            }

            DB::table('workflow_settings')->where('id', $row->id)->update([
                'value' => json_encode(array_values($next)),
            ]);
        }
    }

    private function patchStages(array $stages, array $director): array
    {
        $hasDirector = false;

        foreach ($stages as &$stage) {
            if (($stage['status'] ?? '') === 'dean_review'
                && in_array($stage['label'] ?? '', ['With Dean / Director', 'Dean / Director'], true)) {
                $stage['label'] = 'With Dean';
            }

            if (($stage['status'] ?? '') === 'director_review') {
                $hasDirector = true;
            }
        }
        unset($stage);

        if ($hasDirector) {
            return $stages;
        }

        $next     = [];
        $inserted = false;

        foreach ($stages as $stage) {
            $next[] = $stage;

            if (! $inserted && ($stage['status'] ?? '') === 'dean_review') {
                $next[]   = $director;
                $inserted = true;
            }
        }

        if (! $inserted) {
            $last = array_pop($next);
            $next[] = $director;
            if ($last) {
                $next[] = $last;
            }
        }

        return $next;
    }

    private function moveDirectorsOffDeanSlot(): void
    {
        if (! Schema::hasTable('org_unit_reviewers')) {
            return;
        }

        $directorIds = DB::table('users')
            ->where('position_title', 'like', '%Director%')
            ->pluck('id');

        if ($directorIds->isEmpty()) {
            return;
        }

        $units = DB::table('org_units')
            ->whereIn('dean_user_id', $directorIds)
            ->get(['id', 'dean_user_id']);

        foreach ($units as $unit) {
            $exists = DB::table('org_unit_reviewers')
                ->where('org_unit_id', $unit->id)
                ->where('slot', 'director_review')
                ->first();

            if ($exists) {
                DB::table('org_unit_reviewers')->where('id', $exists->id)->update([
                    'user_id'    => $unit->dean_user_id,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('org_unit_reviewers')->insert([
                    'org_unit_id' => $unit->id,
                    'slot'        => 'director_review',
                    'user_id'     => $unit->dean_user_id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            DB::table('org_units')->where('id', $unit->id)->update(['dean_user_id' => null]);
        }
    }
};
