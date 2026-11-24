<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->string('replacement_status', 20)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 40)->nullable();
            $table->boolean('needs_commitment_approval')->default(false);
            $table->timestamp('line_submitted_at')->nullable();
            $table->timestamp('line_approved_at')->nullable();
            $table->unsignedBigInteger('line_approver_id')->nullable();
            $table->unsignedBigInteger('replaces_indicator_id')->nullable();

            $table->foreign('line_approver_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('replaces_indicator_id')->references('id')->on('pcr_indicators')->nullOnDelete();
        });

        Schema::create('pcr_target_replacements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('old_indicator_id');
            $table->unsignedBigInteger('new_indicator_id')->nullable();
            $table->text('proposed_description');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('old_indicator_id')->references('id')->on('pcr_indicators')->cascadeOnDelete();
            $table->foreign('new_indicator_id')->references('id')->on('pcr_indicators')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['old_indicator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_target_replacements');

        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->dropForeign(['line_approver_id']);
            $table->dropForeign(['replaces_indicator_id']);
            $table->dropColumn([
                'replacement_status',
                'closed_at',
                'closed_reason',
                'needs_commitment_approval',
                'line_submitted_at',
                'line_approved_at',
                'line_approver_id',
                'replaces_indicator_id',
            ]);
        });
    }
};
