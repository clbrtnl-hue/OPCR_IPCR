<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heads keep their own attendance categories. A calendar event in one of
 * those categories is marked per day, and a support line can point at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->string('source', 20);
            $table->boolean('system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['organization_id', 'slug']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('created_by');
            $table->unsignedBigInteger('rating_period_id')->nullable()->after('category_id');

            $table->foreign('category_id')->references('id')->on('attendance_categories')->nullOnDelete();
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->nullOnDelete();
        });

        Schema::create('calendar_event_marks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('user_id');
            $table->date('day');
            $table->string('status', 20);
            $table->unsignedBigInteger('marked_by');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('event_id')->references('id')->on('calendar_events')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('marked_by')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['event_id', 'user_id', 'day']);
        });

        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_category_id')->nullable()->after('title');
            $table->foreign('attendance_category_id')->references('id')->on('attendance_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_category_id');
        });

        Schema::dropIfExists('calendar_event_marks');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('rating_period_id');
        });

        Schema::dropIfExists('attendance_categories');
    }
};
