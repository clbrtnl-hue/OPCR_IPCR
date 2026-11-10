<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IPCR commitments are approved before tasks or cascade. Forms already in the
 * rating lane keep that permission; a draft still has to be sent for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->unsignedBigInteger('commitment_approver_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('commitment_approved_by')->nullable()->after('commitment_approver_id');
            $table->string('commitment_approved_by_name')->nullable()->after('commitment_approved_by');
            $table->timestamp('commitment_approved_at')->nullable()->after('commitment_approved_by_name');

            $table->foreign('commitment_approver_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('commitment_approved_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['commitment_approver_id', 'status']);
        });

        DB::table('pcr_forms')
            ->where('type', 'ipcr')
            ->where('status', '!=', 'draft')
            ->whereNull('commitment_approved_at')
            ->update(['commitment_approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->dropForeign(['commitment_approver_id']);
            $table->dropForeign(['commitment_approved_by']);
            $table->dropIndex(['commitment_approver_id', 'status']);
            $table->dropColumn([
                'commitment_approver_id',
                'commitment_approved_by',
                'commitment_approved_by_name',
                'commitment_approved_at',
            ]);
        });
    }
};
