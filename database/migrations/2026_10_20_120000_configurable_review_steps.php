<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review steps are no longer only Head, Dean / Director, and VP. A college can
 * add a step in Setup. The person who fills it is named on the unit, and the
 * form remembers who was resolved for that step when it was submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pcr_forms MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'draft'");
        }

        Schema::create('org_unit_reviewers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_unit_id');
            $table->string('slot', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->foreign('org_unit_id')->references('id')->on('org_units')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->unique(['org_unit_id', 'slot']);
        });

        Schema::create('pcr_form_reviewers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->string('stage', 40);
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('pcr_forms')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['form_id', 'stage']);
        });

        $this->backfillUnits();
        $this->backfillForms();
    }

    public function down(): void
    {
        Schema::dropIfExists('pcr_form_reviewers');
        Schema::dropIfExists('org_unit_reviewers');
    }

    private function backfillUnits(): void
    {
        $now = now();

        foreach (DB::table('org_units')->get() as $unit) {
            foreach (['head_user_id', 'dean_user_id', 'vp_user_id'] as $slot) {
                if (! $unit->{$slot}) {
                    continue;
                }

                DB::table('org_unit_reviewers')->insert([
                    'org_unit_id' => $unit->id,
                    'slot'        => $slot,
                    'user_id'     => $unit->{$slot},
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    private function backfillForms(): void
    {
        $now = now();

        $stages = [
            'head_review' => ['reviewer' => 'head_reviewer_id', 'by' => 'reviewed_by', 'name' => 'reviewed_by_name', 'at' => 'reviewed_at'],
            'dean_review' => ['reviewer' => 'dean_reviewer_id', 'by' => 'dean_reviewed_by', 'name' => 'dean_reviewed_by_name', 'at' => 'dean_reviewed_at'],
            'vp_review'   => ['reviewer' => 'vp_reviewer_id', 'by' => 'vp_reviewed_by', 'name' => 'vp_reviewed_by_name', 'at' => 'vp_reviewed_at'],
        ];

        foreach (DB::table('pcr_forms')->where('type', 'ipcr')->get() as $form) {
            foreach ($stages as $stage => $columns) {
                if (! $form->{$columns['reviewer']} && ! $form->{$columns['at']}) {
                    continue;
                }

                DB::table('pcr_form_reviewers')->insert([
                    'form_id'          => $form->id,
                    'stage'            => $stage,
                    'reviewer_id'      => $form->{$columns['reviewer']},
                    'reviewed_by'      => $form->{$columns['by']},
                    'reviewed_by_name' => $form->{$columns['name']},
                    'reviewed_at'      => $form->{$columns['at']},
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            }
        }
    }
};
