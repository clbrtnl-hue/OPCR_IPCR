<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A commitment sent for approval used to open the form under My Forms.
 * The president approves it from the review queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('type', 'approval')
            ->where('title', 'like', '%need your approval')
            ->where(function ($query) {
                $query->whereNull('link')->orWhere('link', 'like', '/forms/%');
            })
            ->update(['link' => '/review-queue']);
    }

    public function down(): void
    {
    }
};
