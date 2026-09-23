<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('indicator_id')->nullable();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('stage', ['head', 'vp', 'qa', 'president', 'employee'])->default('head');
            } else {
                $table->string('stage', 20)->default('head');
            }

            $table->text('body');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_role', 30)->nullable();
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('pcr_forms')->onDelete('cascade');
            $table->foreign('indicator_id')->references('id')->on('pcr_indicators')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['form_id', 'indicator_id']);
        });

        Schema::create('pcr_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('performed_by_name')->nullable();
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('pcr_forms')->onDelete('cascade');
            $table->foreign('performed_by')->references('id')->on('users')->onDelete('set null');
            $table->index('form_id');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 40);
            $table->string('description', 500)->nullable();
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('pcr_status_logs');
        Schema::dropIfExists('pcr_comments');
    }
};
