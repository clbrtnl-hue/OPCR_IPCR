<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('image')->nullable();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('role', ['admin', 'president', 'qa', 'vp', 'program_head', 'employee'])
                    ->default('employee');
                $table->enum('status', ['active', 'inactive'])->default('active');
            } else {
                $table->string('role', 30)->default('employee');
                $table->string('status', 20)->default('active');
            }

            $table->string('position_title')->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('role');
            $table->index('org_unit_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
