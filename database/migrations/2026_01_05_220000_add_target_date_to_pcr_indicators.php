<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A (Target + Measure) line is a task, and a task is due on a date. Storing the
 * date lets the system say whether the work is on time, due soon, or overdue —
 * the delay indicator people actually manage against, as opposed to the Q/E/T
 * score QA gives it much later.
 *
 * Delay is derived, never stored: a stored "overdue" flag goes stale the moment
 * the clock passes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->date('target_date')->nullable()->after('description')
                ->comment('When this task is due; delay is derived from it');
            $table->date('completed_on')->nullable()->after('target_date')
                ->comment('Set when progress first reaches completed, so lateness is judged against delivery');

            $table->index('target_date');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->dropIndex(['target_date']);
            $table->dropColumn(['target_date', 'completed_on']);
        });
    }
};
