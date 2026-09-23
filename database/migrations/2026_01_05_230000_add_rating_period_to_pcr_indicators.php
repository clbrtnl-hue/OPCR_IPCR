<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two review points carry different commitments. January–June is not rated
 * on the same lines as July–December, so a success indicator belongs to exactly
 * one rating period rather than being shared across the year.
 *
 * Accomplishments and ratings were already keyed by (indicator, period); now the
 * indicator implies the period, and a write naming a different one is refused.
 *
 * Existing lines belong to the first period of their form's school year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->unsignedBigInteger('rating_period_id')->nullable()->after('output_id');
        });

        // Backfill: the earliest period of each form's school year.
        $firstPeriodByYear = DB::table('rating_periods')
            ->orderBy('seq')
            ->get()
            ->groupBy('school_year_id')
            ->map(fn ($periods) => $periods->first()->id);

        $formYears = DB::table('pcr_forms')->pluck('school_year_id', 'id');

        foreach (DB::table('pcr_outputs')->get() as $output) {
            $yearId = $formYears[$output->form_id] ?? null;
            $period = $yearId ? ($firstPeriodByYear[$yearId] ?? null) : null;

            if ($period) {
                DB::table('pcr_indicators')
                    ->where('output_id', $output->id)
                    ->update(['rating_period_id' => $period]);
            }
        }

        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->index(['output_id', 'rating_period_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->dropForeign('pcr_indicators_rating_period_id_foreign');
            $table->dropIndex(['output_id', 'rating_period_id']);
            $table->dropColumn('rating_period_id');
        });
    }
};
