<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->nullable();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('type', ['college', 'office', 'program'])->default('program');
            } else {
                $table->string('type', 20)->default('program');
            }

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('head_user_id')->nullable();
            $table->unsignedBigInteger('vp_user_id')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('org_units')->onDelete('set null');
            $table->foreign('head_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('vp_user_id')->references('id')->on('users')->onDelete('set null');
            $table->index('parent_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('org_unit_id')->references('id')->on('org_units')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['org_unit_id']);
        });
        Schema::dropIfExists('org_units');
    }
};
