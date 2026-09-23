<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who reviews a form depends on who wrote it, not on a fixed role sequence.
 * Faculty go to their program head then the VP; a program head skips the head
 * stage because they are the head; a VP has neither above them in their unit and
 * falls back to the president. Resolving this per form and storing it here means
 * the queue is a plain lookup, and one person may hold both a head and a VP post
 * without the single `users.role` column stranding their forms.
 *
 * Existing in-flight forms are backfilled so nothing vanishes from a queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->unsignedBigInteger('head_reviewer_id')->nullable()->after('vp_reviewed_by_name');
            $table->unsignedBigInteger('vp_reviewer_id')->nullable()->after('head_reviewer_id');

            $table->foreign('head_reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('vp_reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['head_reviewer_id', 'status']);
            $table->index(['vp_reviewer_id', 'status']);
        });

        // Backfill from the unit slots, minus anyone who would review themselves.
        // Written through the query builder so sqlite (the test driver) agrees.
        $units = DB::table('org_units')->get()->keyBy('id');

        foreach (DB::table('pcr_forms')->get() as $form) {
            $unit = $units->get($form->org_unit_id);

            if (! $unit) {
                continue;
            }

            $head = $unit->head_user_id;
            $vp   = $unit->vp_user_id;

            DB::table('pcr_forms')->where('id', $form->id)->update([
                'head_reviewer_id' => $head && (int) $head !== (int) $form->user_id ? $head : null,
                'vp_reviewer_id'   => $vp && (int) $vp !== (int) $form->user_id ? $vp : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->dropForeign('pcr_forms_head_reviewer_id_foreign');
            $table->dropForeign('pcr_forms_vp_reviewer_id_foreign');
            $table->dropIndex(['head_reviewer_id', 'status']);
            $table->dropIndex(['vp_reviewer_id', 'status']);
            $table->dropColumn(['head_reviewer_id', 'vp_reviewer_id']);
        });
    }
};
