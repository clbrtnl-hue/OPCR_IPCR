<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words on the marked cell at the moment of return. After the owner
 * rewrites that cell and resubmits, the reviewer can still see what it said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_return_marks', function (Blueprint $table) {
            $table->text('previous_value')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_return_marks', function (Blueprint $table) {
            $table->dropColumn('previous_value');
        });
    }
};
