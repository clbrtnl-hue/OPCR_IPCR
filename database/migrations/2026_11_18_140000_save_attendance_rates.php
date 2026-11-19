<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The percentage is part of the attendance record, not only a number
 * calculated while the sheet is open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('rating_period_id');
            $table->string('scope', 40);
            $table->unsignedInteger('present')->default(0);
            $table->unsignedInteger('absent')->default(0);
            $table->unsignedInteger('excused')->default(0);
            $table->unsignedInteger('counted')->default(0);
            $table->unsignedTinyInteger('pct')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->unique(['user_id', 'rating_period_id', 'scope']);
        });

        Schema::table('attendance_meetings', function (Blueprint $table) {
            $table->unsignedInteger('present_count')->default(0);
            $table->unsignedInteger('absent_count')->default(0);
            $table->unsignedInteger('excused_count')->default(0);
            $table->unsignedInteger('counted')->default(0);
            $table->unsignedTinyInteger('pct')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_meetings', function (Blueprint $table) {
            $table->dropColumn(['present_count', 'absent_count', 'excused_count', 'counted', 'pct']);
        });

        Schema::dropIfExists('attendance_rates');
    }
};
