<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A form that is ready for rating used to open under My Forms.
 * That list is QA's own forms. The notice belongs in the review queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('type', 'rating')
            ->where('title', 'like', '%is ready for rating')
            ->where(function ($query) {
                $query->whereNull('link')->orWhere('link', 'like', '/forms/%');
            })
            ->update(['link' => '/review-queue']);
    }

    public function down(): void
    {
    }
};
