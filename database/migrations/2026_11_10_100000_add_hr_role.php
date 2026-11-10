<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Human Resource monitors submission and consolidates ratings. The account
 * sits in the HR office under Administrative and Finance; the role is what
 * lets that office read every form.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'president', 'qa', 'hr', 'vp', 'program_head', 'employee') NOT NULL DEFAULT 'employee'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('users')->where('role', 'hr')->update(['role' => 'employee']);

        DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'president', 'qa', 'vp', 'program_head', 'employee') NOT NULL DEFAULT 'employee'");
    }
};
