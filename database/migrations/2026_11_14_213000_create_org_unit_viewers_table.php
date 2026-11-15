<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People who may open a unit's forms without filling a review step.
 * Several people can watch one unit. The review chain does not read this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit_viewers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_unit_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('org_unit_id')->references('id')->on('org_units')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['org_unit_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_unit_viewers');
    }
};
