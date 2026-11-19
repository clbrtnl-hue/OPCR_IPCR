<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_status_logs', function (Blueprint $table) {
            $table->json('scores')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_status_logs', function (Blueprint $table) {
            $table->dropColumn('scores');
        });
    }
};
