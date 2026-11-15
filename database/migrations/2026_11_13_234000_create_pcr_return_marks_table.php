<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return from commitment approval names the cells that need fixing,
 * so the writer can see the place and the note together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_return_marks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('status_log_id')->nullable();
            $table->unsignedBigInteger('output_id')->nullable();
            $table->unsignedBigInteger('indicator_id')->nullable();
            $table->string('field', 40);
            $table->string('note', 500);
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('pcr_forms')->onDelete('cascade');
            $table->foreign('status_log_id')->references('id')->on('pcr_status_logs')->onDelete('cascade');
            $table->foreign('output_id')->references('id')->on('pcr_outputs')->onDelete('cascade');
            $table->foreign('indicator_id')->references('id')->on('pcr_indicators')->onDelete('cascade');
            $table->index(['form_id', 'status_log_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_return_marks');
    }
};
