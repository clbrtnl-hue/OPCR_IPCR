<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The OPCR is planned before it is delivered. Admin drafts the office targets,
 * QA approves them, and admin publishes — only a published OPCR is visible to
 * everyone else as something their IPCR can be tied to. Rating happens later,
 * at each period's end, on the same document.
 *
 *   draft -> qa_approval -> approved -> published -> qa_rating -> rated -> final
 *
 * The IPCR keeps its own chain (head_review -> vp_review -> qa_rating -> ...);
 * both types share this column, so the widened list is the union of the two.
 */
return new class extends Migration
{
    private const ALL = [
        'draft', 'head_review', 'vp_review',
        'qa_approval', 'approved', 'published',
        'qa_rating', 'rated', 'final', 'returned',
    ];

    private const ORIGINAL = [
        'draft', 'head_review', 'vp_review',
        'qa_rating', 'rated', 'final', 'returned',
    ];

    public function up(): void
    {
        $this->setStatusValues(self::ALL);
    }

    public function down(): void
    {
        DB::table('pcr_forms')
            ->whereIn('status', ['qa_approval', 'approved', 'published'])
            ->update(['status' => 'draft']);

        $this->setStatusValues(self::ORIGINAL);
    }

    private function setStatusValues(array $values): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));
            DB::statement("ALTER TABLE pcr_forms MODIFY COLUMN status ENUM({$list}) NOT NULL DEFAULT 'draft'");

            return;
        }

        // sqlite keeps a plain string column; widen it rather than rebuild.
        Schema::table('pcr_forms', function ($table) {
            $table->string('status', 20)->default('draft')->change();
        });
    }
};
