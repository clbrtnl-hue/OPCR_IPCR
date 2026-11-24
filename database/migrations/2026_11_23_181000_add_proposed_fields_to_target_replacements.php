<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_target_replacements', function (Blueprint $table) {
            $table->json('proposed_user_ids')->nullable()->after('proposed_description');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_target_replacements', function (Blueprint $table) {
            $table->dropColumn('proposed_user_ids');
        });
    }
};
