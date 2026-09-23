<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An IPCR is filed twice a year — one form per rating period, each running its
 * own review chain. The period therefore lives on the form itself; an OPCR
 * still covers the whole year and keeps a null period with per-line periods.
 *
 * Existing IPCRs are pinned to the earliest period their lines mention (the
 * year's first period when they have none). A form whose lines span both
 * periods is split: each extra period gets a sibling form with cloned
 * headings, and its indicators and period summary move over. The unique slot
 * gains the period column only after that backfill, so the widened key never
 * sees two same-period siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->unsignedBigInteger('rating_period_id')->nullable()->after('org_unit_id');
        });

        $firstPeriodByYear = DB::table('rating_periods')
            ->orderBy('seq')
            ->get()
            ->groupBy('school_year_id')
            ->map(fn ($periods) => $periods->first()->id);

        $seqById = DB::table('rating_periods')->pluck('seq', 'id');

        foreach (DB::table('pcr_forms')->where('type', 'ipcr')->get() as $form) {
            $linePeriods = DB::table('pcr_indicators')
                ->join('pcr_outputs', 'pcr_outputs.id', '=', 'pcr_indicators.output_id')
                ->where('pcr_outputs.form_id', $form->id)
                ->whereNotNull('pcr_indicators.rating_period_id')
                ->distinct()
                ->pluck('pcr_indicators.rating_period_id')
                ->sortBy(fn ($id) => $seqById[$id] ?? PHP_INT_MAX)
                ->values();

            $primary = $linePeriods->first()
                ?? $firstPeriodByYear[$form->school_year_id]
                ?? null;

            DB::table('pcr_forms')->where('id', $form->id)->update(['rating_period_id' => $primary]);

            foreach ($linePeriods->slice(1) as $extraPeriod) {
                $sibling = (array) $form;
                unset($sibling['id']);
                $sibling['rating_period_id'] = $extraPeriod;

                $siblingId = DB::table('pcr_forms')->insertGetId($sibling);

                foreach (DB::table('pcr_outputs')->where('form_id', $form->id)->get() as $output) {
                    $clone = (array) $output;
                    unset($clone['id']);
                    $clone['form_id'] = $siblingId;

                    $cloneId = DB::table('pcr_outputs')->insertGetId($clone);

                    DB::table('pcr_indicators')
                        ->where('output_id', $output->id)
                        ->where('rating_period_id', $extraPeriod)
                        ->update(['output_id' => $cloneId]);
                }

                DB::table('pcr_period_summaries')
                    ->where('form_id', $form->id)
                    ->where('rating_period_id', $extraPeriod)
                    ->update(['form_id' => $siblingId]);
            }
        }

        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->dropUnique('pcr_forms_slot_unique');
            $table->unique(
                ['type', 'school_year_id', 'org_unit_id', 'user_id', 'rating_period_id'],
                'pcr_forms_slot_unique'
            );
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->index(['type', 'school_year_id', 'rating_period_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->dropForeign('pcr_forms_rating_period_id_foreign');
            $table->dropIndex(['type', 'school_year_id', 'rating_period_id']);
            $table->dropUnique('pcr_forms_slot_unique');
            $table->unique(['type', 'school_year_id', 'org_unit_id', 'user_id'], 'pcr_forms_slot_unique');
            $table->dropColumn('rating_period_id');
        });
    }
};
