<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cut-off. When an administrator locks a rating period, every form in it
 * becomes read-only for everyone else: no editing commitments, no recording
 * accomplishments, no uploading or deleting evidence, no rating. Admin can
 * still act, and can unlock.
 *
 * This is separate from `status`: a period can be closed for new work while
 * still open for QA, and locking is the explicit, audited freeze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rating_periods', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('status')
                ->comment('Locked periods are view-only for everyone but admin');
            $table->timestamp('locked_at')->nullable()->after('is_locked');
            $table->unsignedBigInteger('locked_by')->nullable()->after('locked_at');
            $table->string('locked_by_name')->nullable()->after('locked_by');

            $table->foreign('locked_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('rating_periods', function (Blueprint $table) {
            $table->dropForeign('rating_periods_locked_by_foreign');
            $table->dropColumn(['is_locked', 'locked_at', 'locked_by', 'locked_by_name']);
        });
    }
};
