<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Only Opol Community College uses this system today and the UI
 * stays single-tenant, but every tenant-owned record hangs off this table so a
 * second organization is a data change rather than a rewrite.
 *
 * The record also owns the identity that the printed OPCR/IPCR header and the
 * login screen used to hardcode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 60)->nullable();
            $table->string('code', 30)->nullable()->comment('Slug used for future tenant routing');
            $table->string('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('head_title', 120)->nullable()
                ->comment('Printed under the approving signature, e.g. "College President"');

            if (DB::getDriverName() === 'mysql') {
                $table->enum('status', ['active', 'inactive'])->default('active');
            } else {
                $table->string('status', 20)->default('active');
            }

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
