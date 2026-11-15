<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Head, dean, and VP were stored twice: once on the unit or the form, and
 * again in the reviewer tables. The columns were the ones the app kept
 * writing, so they win where the two copies disagree. After this, the
 * reviewer tables are the only copy.
 */
return new class extends Migration
{
    private const UNIT_SLOTS = ['head_user_id', 'dean_user_id', 'vp_user_id'];

    private const FORM_STAGES = [
        'head_review' => [
            'reviewer_id'     => 'head_reviewer_id',
            'reviewed_by'     => 'reviewed_by',
            'reviewed_by_name'=> 'reviewed_by_name',
            'reviewed_at'     => 'reviewed_at',
        ],
        'dean_review' => [
            'reviewer_id'     => 'dean_reviewer_id',
            'reviewed_by'     => 'dean_reviewed_by',
            'reviewed_by_name'=> 'dean_reviewed_by_name',
            'reviewed_at'     => 'dean_reviewed_at',
        ],
        'vp_review' => [
            'reviewer_id'     => 'vp_reviewer_id',
            'reviewed_by'     => 'vp_reviewed_by',
            'reviewed_by_name'=> 'vp_reviewed_by_name',
            'reviewed_at'     => 'vp_reviewed_at',
        ],
    ];

    public function up(): void
    {
        $this->copyUnits();
        $this->copyForms();
        $this->dropFormColumns();
        $this->dropUnitColumns();
    }

    public function down(): void
    {
        $this->restoreUnitColumns();
        $this->restoreFormColumns();
        $this->copyBack();
    }

    private function copyUnits(): void
    {
        $now = now();

        foreach (DB::table('org_units')->get(['id', 'head_user_id', 'dean_user_id', 'vp_user_id']) as $unit) {
            foreach (self::UNIT_SLOTS as $slot) {
                $existing = DB::table('org_unit_reviewers')
                    ->where('org_unit_id', $unit->id)
                    ->where('slot', $slot)
                    ->first();

                if ($existing) {
                    DB::table('org_unit_reviewers')->where('id', $existing->id)->update([
                        'user_id'    => $unit->{$slot},
                        'updated_at' => $now,
                    ]);

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

    private function copyForms(): void
    {
        $now = now();
        $columns = ['id'];

        foreach (self::FORM_STAGES as $fields) {
            foreach ($fields as $column) {
                $columns[] = $column;
            }
        }

        foreach (DB::table('pcr_forms')->get($columns) as $form) {
            foreach (self::FORM_STAGES as $stage => $fields) {
                $values = [];

                foreach ($fields as $stored => $column) {
                    $values[$stored] = $form->{$column};
                }

                $existing = DB::table('pcr_form_reviewers')
                    ->where('form_id', $form->id)
                    ->where('stage', $stage)
                    ->first();

                $filled = collect($values)->contains(fn ($value) => $value !== null);

                if (! $existing && ! $filled) {
                    continue;
                }

                if ($existing) {
                    DB::table('pcr_form_reviewers')->where('id', $existing->id)->update($values + [
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                DB::table('pcr_form_reviewers')->insert($values + [
                    'form_id'    => $form->id,
                    'stage'      => $stage,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function dropFormColumns(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->dropForeign(['head_reviewer_id']);
            $table->dropForeign(['dean_reviewer_id']);
            $table->dropForeign(['vp_reviewer_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['dean_reviewed_by']);
            $table->dropForeign(['vp_reviewed_by']);
            $table->dropIndex(['head_reviewer_id', 'status']);
            $table->dropIndex(['dean_reviewer_id', 'status']);
            $table->dropIndex(['vp_reviewer_id', 'status']);
            $table->dropColumn([
                'head_reviewer_id', 'dean_reviewer_id', 'vp_reviewer_id',
                'reviewed_by', 'reviewed_by_name', 'reviewed_at',
                'dean_reviewed_by', 'dean_reviewed_by_name', 'dean_reviewed_at',
                'vp_reviewed_by', 'vp_reviewed_by_name', 'vp_reviewed_at',
            ]);
        });
    }

    private function dropUnitColumns(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->dropForeign(['head_user_id']);
            $table->dropForeign(['dean_user_id']);
            $table->dropForeign(['vp_user_id']);
            $table->dropColumn(['head_user_id', 'dean_user_id', 'vp_user_id']);
        });
    }

    private function restoreUnitColumns(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->unsignedBigInteger('head_user_id')->nullable();
            $table->unsignedBigInteger('dean_user_id')->nullable();
            $table->unsignedBigInteger('vp_user_id')->nullable();
            $table->foreign('head_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dean_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('vp_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    private function restoreFormColumns(): void
    {
        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->unsignedBigInteger('head_reviewer_id')->nullable();
            $table->unsignedBigInteger('dean_reviewer_id')->nullable();
            $table->unsignedBigInteger('vp_reviewer_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('dean_reviewed_by')->nullable();
            $table->string('dean_reviewed_by_name')->nullable();
            $table->timestamp('dean_reviewed_at')->nullable();
            $table->unsignedBigInteger('vp_reviewed_by')->nullable();
            $table->string('vp_reviewed_by_name')->nullable();
            $table->timestamp('vp_reviewed_at')->nullable();

            $table->foreign('head_reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dean_reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('vp_reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dean_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('vp_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['head_reviewer_id', 'status']);
            $table->index(['dean_reviewer_id', 'status']);
            $table->index(['vp_reviewer_id', 'status']);
        });
    }

    private function copyBack(): void
    {
        foreach (DB::table('org_unit_reviewers')->whereIn('slot', self::UNIT_SLOTS)->get() as $row) {
            DB::table('org_units')->where('id', $row->org_unit_id)->update([
                $row->slot => $row->user_id,
            ]);
        }

        foreach (DB::table('pcr_form_reviewers')->whereIn('stage', array_keys(self::FORM_STAGES))->get() as $row) {
            $fields = self::FORM_STAGES[$row->stage];

            DB::table('pcr_forms')->where('id', $row->form_id)->update([
                $fields['reviewer_id']      => $row->reviewer_id,
                $fields['reviewed_by']      => $row->reviewed_by,
                $fields['reviewed_by_name'] => $row->reviewed_by_name,
                $fields['reviewed_at']      => $row->reviewed_at,
            ]);
        }
    }
};
