<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delegated line records who handed it over, so the assignee can see where
 * the work came from and the trail survives the assigner changing posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_by')->nullable()->after('parent_indicator_id');
            $table->string('assigned_by_name')->nullable()->after('assigned_by');

            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->dropForeign('pcr_indicators_assigned_by_foreign');
            $table->dropColumn(['assigned_by', 'assigned_by_name']);
        });
    }
};
