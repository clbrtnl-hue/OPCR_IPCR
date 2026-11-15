<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An OPCR sent for approval used to open the document under My Forms.
 * That list is the reviewer's own forms. The notice belongs in the queue,
 * where the targets can be read before they are approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('notifications as n')
            ->join('pcr_forms as f', 'f.id', '=', 'n.form_id')
            ->where('n.type', 'approval')
            ->where('f.type', 'opcr')
            ->pluck('n.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('notifications')->whereIn('id', $ids)->update(['link' => '/review-queue']);
    }

    public function down(): void
    {
    }
};
