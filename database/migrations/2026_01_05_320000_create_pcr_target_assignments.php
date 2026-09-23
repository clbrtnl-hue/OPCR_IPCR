<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Being made accountable for an office target is not the same as receiving
 * that target's wording. The assignee opens their IPCR and writes their own
 * commitments, linked back to this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_target_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->string('assigned_by_name');
            $table->unsignedBigInteger('rating_period_id')->nullable();
            $table->timestamps();

            $table->foreign('indicator_id')->references('id')->on('pcr_indicators')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');

            $table->unique(
                ['indicator_id', 'user_id', 'rating_period_id'],
                'pcr_target_assignments_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_target_assignments');
    }
};
