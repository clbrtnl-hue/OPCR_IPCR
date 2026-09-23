<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_forms', function (Blueprint $table) {
            $table->id();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('type', ['opcr', 'ipcr']);
                $table->enum('status', [
                    'draft', 'head_review', 'vp_review',
                    'qa_rating', 'rated', 'final', 'returned',
                ])->default('draft');
            } else {
                $table->string('type', 10);
                $table->string('status', 20)->default('draft');
            }

            $table->unsignedBigInteger('school_year_id');
            $table->unsignedBigInteger('org_unit_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('header_note')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('vp_reviewed_by')->nullable();
            $table->string('vp_reviewed_by_name')->nullable();
            $table->timestamp('vp_reviewed_at')->nullable();
            $table->unsignedBigInteger('rated_by')->nullable();
            $table->string('rated_by_name')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamps();

            $table->foreign('school_year_id')->references('id')->on('school_years')->onDelete('cascade');
            $table->foreign('org_unit_id')->references('id')->on('org_units')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('vp_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rated_by')->references('id')->on('users')->onDelete('set null');

            $table->unique(['type', 'school_year_id', 'org_unit_id', 'user_id'], 'pcr_forms_slot_unique');
            $table->index('status');
        });

        Schema::create('pcr_outputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');

            if (DB::getDriverName() === 'mysql') {
                $table->enum('section', ['strategic', 'core', 'support'])->default('core');
            } else {
                $table->string('section', 20)->default('core');
            }

            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('pcr_forms')->onDelete('cascade');
            $table->index(['form_id', 'section']);
        });

        Schema::create('pcr_indicators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('output_id');
            $table->text('description');
            $table->decimal('allotted_budget', 14, 2)->nullable();
            $table->string('accountable')->nullable();
            $table->unsignedBigInteger('opcr_indicator_id')->nullable();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('progress_status', ['not_started', 'ongoing', 'completed', 'deferred'])
                    ->default('not_started');
            } else {
                $table->string('progress_status', 20)->default('not_started');
            }

            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('output_id')->references('id')->on('pcr_outputs')->onDelete('cascade');
            $table->foreign('opcr_indicator_id')->references('id')->on('pcr_indicators')->onDelete('set null');
            $table->index('output_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_indicators');
        Schema::dropIfExists('pcr_outputs');
        Schema::dropIfExists('pcr_forms');
    }
};
