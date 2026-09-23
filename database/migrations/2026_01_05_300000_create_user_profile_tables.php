<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Personal Data Sheet (CS Form 212), the record every government employee
 * keeps. `users` held only enough to sign in and be rated; this is who the
 * person actually is — schooling, eligibility, service and training.
 *
 * Tenancy is inherited through `users`, so no organization_id here. The
 * government identifiers on `user_profiles` are personal data and are served
 * only to people who pass UserProfilePolicy — never in the public card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();

            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();

            if (DB::getDriverName() === 'mysql') {
                $table->enum('sex', ['male', 'female'])->nullable();
                $table->enum('civil_status', ['single', 'married', 'widowed', 'separated', 'other'])->nullable();
            } else {
                $table->string('sex', 10)->nullable();
                $table->string('civil_status', 20)->nullable();
            }

            $table->string('citizenship', 60)->nullable();
            $table->decimal('height_m', 4, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('blood_type', 6)->nullable();

            // Identifiers: personal data, never in the public card.
            $table->string('gsis_id', 40)->nullable();
            $table->string('pagibig_id', 40)->nullable();
            $table->string('philhealth_id', 40)->nullable();
            $table->string('sss_id', 40)->nullable();
            $table->string('tin', 40)->nullable();
            $table->string('agency_employee_no', 40)->nullable();

            $table->string('residential_address')->nullable();
            $table->string('permanent_address')->nullable();
            $table->string('telephone', 40)->nullable();
            $table->string('mobile', 40)->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // The repeating sections. Each is a plain ordered list against a person.
        $this->repeating('user_educations', function (Blueprint $table) {
            $table->string('level', 40)->comment('elementary, secondary, vocational, college, graduate');
            $table->string('school');
            $table->string('degree')->nullable();
            $table->string('period_from', 12)->nullable();
            $table->string('period_to', 12)->nullable();
            $table->string('units_earned', 60)->nullable();
            $table->string('year_graduated', 12)->nullable();
            $table->string('honours')->nullable();
        });

        $this->repeating('user_eligibilities', function (Blueprint $table) {
            $table->string('eligibility');
            $table->string('rating', 20)->nullable();
            $table->date('examination_date')->nullable();
            $table->string('examination_place')->nullable();
            $table->string('licence_number', 60)->nullable();
            $table->date('licence_valid_until')->nullable();
        });

        $this->repeating('user_work_experiences', function (Blueprint $table) {
            $table->string('position');
            $table->string('company');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('monthly_salary', 40)->nullable();
            $table->string('salary_grade', 20)->nullable();
            $table->string('appointment_status', 60)->nullable();
            $table->boolean('is_government')->default(false);
        });

        $this->repeating('user_trainings', function (Blueprint $table) {
            $table->string('title');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->unsignedInteger('hours')->nullable();
            $table->string('kind', 60)->nullable()->comment('managerial, supervisory, technical, other');
            $table->string('conducted_by')->nullable();
        });

        $this->repeating('user_voluntary_works', function (Blueprint $table) {
            $table->string('organization');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->unsignedInteger('hours')->nullable();
            $table->string('position')->nullable();
        });
    }

    private function repeating(string $name, callable $columns): void
    {
        Schema::create($name, function (Blueprint $table) use ($columns) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            $columns($table);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        foreach ([
            'user_voluntary_works', 'user_trainings', 'user_work_experiences',
            'user_eligibilities', 'user_educations', 'user_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
