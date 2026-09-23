<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60)->unique();
            $table->date('start_date');
            $table->date('end_date');

            if (DB::getDriverName() === 'mysql') {
                $table->enum('status', ['open', 'closed'])->default('open');
            } else {
                $table->string('status', 20)->default('open');
            }

            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('rating_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_year_id');
            $table->unsignedTinyInteger('seq');
            $table->string('label', 60);
            $table->date('opens_at')->nullable();
            $table->date('closes_at')->nullable();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('status', ['upcoming', 'open', 'closed'])->default('upcoming');
            } else {
                $table->string('status', 20)->default('upcoming');
            }

            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->foreign('school_year_id')->references('id')->on('school_years')->onDelete('cascade');
            $table->unique(['school_year_id', 'seq']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_periods');
        Schema::dropIfExists('school_years');
    }
};
