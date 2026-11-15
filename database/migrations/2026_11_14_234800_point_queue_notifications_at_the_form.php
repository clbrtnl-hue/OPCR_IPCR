<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Approval and rating notices used to open the review queue list. The form
 * id was already stored, so each of those notices can open that form.
 * A notice with a form and no link opens the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('link', '/review-queue')
            ->whereNotNull('form_id')
            ->orderBy('id')
            ->select(['id', 'form_id'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('notifications')->where('id', $row->id)->update([
                        'link' => '/forms/' . $row->form_id,
                    ]);
                }
            });

        DB::table('notifications')
            ->whereNull('link')
            ->whereNotNull('form_id')
            ->orderBy('id')
            ->select(['id', 'form_id'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('notifications')->where('id', $row->id)->update([
                        'link' => '/forms/' . $row->form_id,
                    ]);
                }
            });
    }

    public function down(): void
    {
    }
};
