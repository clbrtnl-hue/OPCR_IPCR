<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A program head is not the last person before the VP. Where a unit names a
 * dean, the IPCR goes head → dean → VP → QA. A unit with no dean skips that
 * stage, so offices that report straight to a VP keep the chain they have.
 */
return new class extends Migration
{
    private const STATUSES = [
        'draft', 'head_review', 'dean_review', 'vp_review',
        'qa_approval', 'approved', 'published',
        'qa_rating', 'rated', 'final', 'returned',
    ];

    private const PREVIOUS = [
        'draft', 'head_review', 'vp_review',
        'qa_approval', 'approved', 'published',
        'qa_rating', 'rated', 'final', 'returned',
    ];

    public function up(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->unsignedBigInteger('dean_user_id')->nullable()->after('head_user_id');
            $table->foreign('dean_user_id')->references('id')->on('users')->onDelete('set null');
        });

        $this->setStatusValues(self::STATUSES);

        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->unsignedBigInteger('dean_reviewer_id')->nullable()->after('head_reviewer_id');
            $table->unsignedBigInteger('dean_reviewed_by')->nullable()->after('dean_reviewer_id');
            $table->string('dean_reviewed_by_name')->nullable()->after('dean_reviewed_by');
            $table->timestamp('dean_reviewed_at')->nullable()->after('dean_reviewed_by_name');

            $table->foreign('dean_reviewer_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dean_reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['dean_reviewer_id', 'status']);
        });

        $this->insertDeanStage();
    }

    public function down(): void
    {
        DB::table('pcr_forms')->where('status', 'dean_review')->update(['status' => 'vp_review']);

        Schema::table('pcr_forms', function (Blueprint $table) {
            $table->dropForeign(['dean_reviewer_id']);
            $table->dropForeign(['dean_reviewed_by']);
            $table->dropIndex(['dean_reviewer_id', 'status']);
            $table->dropColumn([
                'dean_reviewer_id', 'dean_reviewed_by', 'dean_reviewed_by_name', 'dean_reviewed_at',
            ]);
        });

        Schema::table('org_units', function (Blueprint $table) {
            $table->dropForeign(['dean_user_id']);
            $table->dropColumn('dean_user_id');
        });

        $this->setStatusValues(self::PREVIOUS);
        $this->removeDeanStage();
    }

    private function setStatusValues(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = implode(',', array_map(fn ($value) => "'{$value}'", $values));
        DB::statement("ALTER TABLE pcr_forms MODIFY COLUMN status ENUM({$list}) NOT NULL DEFAULT 'draft'");
    }

    /** A saved review chain gains the dean stage after the head, unless it already has one. */
    private function insertDeanStage(): void
    {
        $dean = [
            'status'    => 'dean_review',
            'label'     => 'With Dean / Director',
            'source'    => 'unit_slot',
            'slot'      => 'dean_user_id',
            'skippable' => true,
        ];

        foreach (DB::table('workflow_settings')->where('key', 'review_stages')->get() as $row) {
            $stages = is_string($row->value) ? json_decode($row->value, true) : $row->value;

            if (! is_array($stages) || $this->hasDean($stages)) {
                continue;
            }

            $next     = [];
            $inserted = false;

            foreach ($stages as $stage) {
                $next[] = $stage;

                if (! $inserted && ($stage['status'] ?? '') === 'head_review') {
                    $next[]   = $dean;
                    $inserted = true;
                }
            }

            if (! $inserted) {
                array_unshift($next, $dean);
            }

            DB::table('workflow_settings')->where('id', $row->id)->update([
                'value' => json_encode(array_values($next)),
            ]);
        }
    }

    private function removeDeanStage(): void
    {
        foreach (DB::table('workflow_settings')->where('key', 'review_stages')->get() as $row) {
            $stages = is_string($row->value) ? json_decode($row->value, true) : $row->value;

            if (! is_array($stages)) {
                continue;
            }

            $next = array_values(array_filter(
                $stages,
                fn ($stage) => ($stage['status'] ?? '') !== 'dean_review'
            ));

            DB::table('workflow_settings')->where('id', $row->id)->update([
                'value' => json_encode($next),
            ]);
        }
    }

    private function hasDean(array $stages): bool
    {
        foreach ($stages as $stage) {
            if (($stage['status'] ?? '') === 'dean_review') {
                return true;
            }
        }

        return false;
    }
};
