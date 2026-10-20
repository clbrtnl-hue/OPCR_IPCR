<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The middle reviewer is a dean in an academic unit and a director in another
 * office. A chain saved before that wording is renamed in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('With Dean', 'With Dean / Director');
    }

    public function down(): void
    {
        $this->rename('With Dean / Director', 'With Dean');
    }

    private function rename(string $from, string $to): void
    {
        foreach (DB::table('workflow_settings')->where('key', 'review_stages')->get() as $row) {
            $stages = is_string($row->value) ? json_decode($row->value, true) : $row->value;

            if (! is_array($stages)) {
                continue;
            }

            $changed = false;

            foreach ($stages as &$stage) {
                if (($stage['status'] ?? '') === 'dean_review' && ($stage['label'] ?? '') === $from) {
                    $stage['label'] = $to;
                    $changed = true;
                }
            }
            unset($stage);

            if (! $changed) {
                continue;
            }

            DB::table('workflow_settings')->where('id', $row->id)->update([
                'value' => json_encode(array_values($stages)),
            ]);
        }
    }
};
