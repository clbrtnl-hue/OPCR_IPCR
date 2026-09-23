<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('rating_period_id');
            $table->unsignedTinyInteger('q')->nullable();
            $table->unsignedTinyInteger('e')->nullable();
            $table->unsignedTinyInteger('t')->nullable();
            $table->decimal('a', 3, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('rated_by')->nullable();
            $table->string('rated_by_name')->nullable();
            $table->timestamps();

            $table->foreign('indicator_id')->references('id')->on('pcr_indicators')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->foreign('rated_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['indicator_id', 'rating_period_id'], 'pcr_rating_slot_unique');
        });

        Schema::create('pcr_period_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('rating_period_id');
            $table->decimal('strategic_average', 4, 2)->nullable();
            $table->decimal('core_average', 4, 2)->nullable();
            $table->decimal('support_average', 4, 2)->nullable();
            $table->decimal('final_average', 4, 2)->nullable();
            $table->string('adjectival', 40)->nullable();
            $table->unsignedInteger('rated_indicators')->default(0);
            $table->unsignedInteger('total_indicators')->default(0);
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('pcr_forms')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->unique(['form_id', 'rating_period_id'], 'pcr_summary_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_period_summaries');
        Schema::dropIfExists('pcr_ratings');
    }
};
