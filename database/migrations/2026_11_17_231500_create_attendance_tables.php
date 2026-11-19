<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unit leader records two kinds of attendance.
 * Weekdays are a standing sheet. Meetings are set one by one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('rating_period_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('marked_by');
            $table->date('day');
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('marked_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'day']);
        });

        Schema::create('attendance_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('org_unit_id');
            $table->unsignedBigInteger('rating_period_id');
            $table->unsignedBigInteger('created_by');
            $table->string('title', 200);
            $table->string('category', 40);
            $table->date('held_on');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('org_unit_id')->references('id')->on('org_units')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['created_by', 'rating_period_id']);
        });

        Schema::create('attendance_meeting_marks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('meeting_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('meeting_id')->references('id')->on('attendance_meetings')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_meeting_marks');
        Schema::dropIfExists('attendance_meetings');
        Schema::dropIfExists('attendance_days');
    }
};
