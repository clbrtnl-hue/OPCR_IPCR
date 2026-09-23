<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An IPCR does not invent its own headings: the MFO/PPA it commits under is one
 * the college already named in its OPCR. Tagging the output to that OPCR output
 * means "Research" on somebody's IPCR is demonstrably the college's Research
 * programme rather than a phrase that happens to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('opcr_output_id')->nullable()->after('form_id')
                ->comment('The OPCR MFO/PPA this heading answers to');

            $table->foreign('opcr_output_id')->references('id')->on('pcr_outputs')->onDelete('set null');
            $table->index('opcr_output_id');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_outputs', function (Blueprint $table) {
            $table->dropForeign('pcr_outputs_opcr_output_id_foreign');
            $table->dropIndex(['opcr_output_id']);
            $table->dropColumn('opcr_output_id');
        });
    }
};
