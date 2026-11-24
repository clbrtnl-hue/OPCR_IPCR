<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The HR account is a head of one office, the same as any other unit head.
 * Closing a rating stays with QA.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'hr')->update(['role' => 'program_head']);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'president', 'qa', 'vp', 'program_head', 'employee') NOT NULL DEFAULT 'employee'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'president', 'qa', 'hr', 'vp', 'program_head', 'employee') NOT NULL DEFAULT 'employee'");
    }
};
