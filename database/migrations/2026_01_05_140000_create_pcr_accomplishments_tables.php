<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_accomplishments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('indicator_id');
            $table->unsignedBigInteger('rating_period_id');
            $table->text('actual_accomplishment')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('indicator_id')->references('id')->on('pcr_indicators')->onDelete('cascade');
            $table->foreign('rating_period_id')->references('id')->on('rating_periods')->onDelete('cascade');
            $table->unique(['indicator_id', 'rating_period_id'], 'pcr_accomp_slot_unique');
        });

        Schema::create('pcr_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accomplishment_id');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->timestamps();

            $table->foreign('accomplishment_id')->references('id')->on('pcr_accomplishments')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
            $table->index('accomplishment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_attachments');
        Schema::dropIfExists('pcr_accomplishments');
    }
};
