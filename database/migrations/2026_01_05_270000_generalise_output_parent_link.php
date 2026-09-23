<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Headings cascade the same way lines do. The college names an MFO/PPA, a head
 * commits under it, and their faculty commit under the head's — so the column
 * holds whatever heading sits directly above, which is not always the OPCR's.
 *
 * Also records who handed the heading over, mirroring the stamp already on
 * pcr_indicators.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('pcr_outputs', function (Blueprint $table) {
                $table->dropForeign('pcr_outputs_opcr_output_id_foreign');
                $table->dropIndex(['opcr_output_id']);
            });
        }

        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->renameColumn('opcr_output_id', 'parent_output_id');
        });

        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_by')->nullable()->after('parent_output_id');
            $table->string('assigned_by_name')->nullable()->after('assigned_by');
        });

        if ($isMysql) {
            Schema::table('pcr_outputs', function (Blueprint $table) {
                $table->foreign('parent_output_id')->references('id')->on('pcr_outputs')->onDelete('set null');
                $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
                $table->index('parent_output_id');
            });
        }

        $this->backfill();
    }

    /**
     * Anything already assigned matched headings by title. Tie those to the
     * heading they were copied from so nothing orphans once the roll-up starts
     * climbing this chain.
     */
    private function backfill(): void
    {
        $opcrOutputs = DB::table('pcr_outputs')
            ->join('pcr_forms', 'pcr_forms.id', '=', 'pcr_outputs.form_id')
            ->where('pcr_forms.type', 'opcr')
            ->select('pcr_outputs.id', 'pcr_outputs.section', 'pcr_outputs.title', 'pcr_forms.school_year_id')
            ->get();

        if ($opcrOutputs->isEmpty()) {
            return;
        }

        $ipcrOutputs = DB::table('pcr_outputs')
            ->join('pcr_forms', 'pcr_forms.id', '=', 'pcr_outputs.form_id')
            ->where('pcr_forms.type', 'ipcr')
            ->select('pcr_outputs.id', 'pcr_outputs.section', 'pcr_outputs.title', 'pcr_forms.school_year_id')
            ->get();

        foreach ($ipcrOutputs as $output) {
            $match = $opcrOutputs->first(
                fn ($o) => $o->section === $output->section
                    && $o->title === $output->title
                    && (int) $o->school_year_id === (int) $output->school_year_id
            );

            if ($match) {
                DB::table('pcr_outputs')->where('id', $output->id)->update(['parent_output_id' => $match->id]);
            }
        }
    }

    public function down(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('pcr_outputs', function (Blueprint $table) {
                $table->dropForeign('pcr_outputs_parent_output_id_foreign');
                $table->dropForeign('pcr_outputs_assigned_by_foreign');
                $table->dropIndex(['parent_output_id']);
            });
        }

        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->dropColumn(['assigned_by', 'assigned_by_name']);
            $table->renameColumn('parent_output_id', 'opcr_output_id');
        });

        if ($isMysql) {
            Schema::table('pcr_outputs', function (Blueprint $table) {
                $table->foreign('opcr_output_id')->references('id')->on('pcr_outputs')->onDelete('set null');
            });
        }
    }
};
