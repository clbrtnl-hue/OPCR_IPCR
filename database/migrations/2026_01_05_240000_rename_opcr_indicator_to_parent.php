<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commitments cascade further than one hop: an office target is delivered by a
 * head, whose line is delivered by faculty, whose line may be delegated again.
 * The column therefore holds whatever line sits directly above this one, which
 * is not always an OPCR — so `opcr_indicator_id` stops describing its contents.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        // sqlite rebuilds the table on rename and carries its keys with it; it
        // also cannot drop a foreign key by name, so only MySQL does this dance.
        if ($isMysql) {
            Schema::table('pcr_indicators', function (Blueprint $table) {
                $table->dropForeign('pcr_indicators_opcr_indicator_id_foreign');
            });
        }

        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->renameColumn('opcr_indicator_id', 'parent_indicator_id');
        });

        if ($isMysql) {
            Schema::table('pcr_indicators', function (Blueprint $table) {
                $table->foreign('parent_indicator_id')->references('id')->on('pcr_indicators')->onDelete('set null');
                $table->index('parent_indicator_id');
            });
        }
    }

    public function down(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('pcr_indicators', function (Blueprint $table) {
                $table->dropForeign('pcr_indicators_parent_indicator_id_foreign');
                $table->dropIndex(['parent_indicator_id']);
            });
        }

        Schema::table('pcr_indicators', function (Blueprint $table) {
            $table->renameColumn('parent_indicator_id', 'opcr_indicator_id');
        });

        if ($isMysql) {
            Schema::table('pcr_indicators', function (Blueprint $table) {
                $table->foreign('opcr_indicator_id')->references('id')->on('pcr_indicators')->onDelete('set null');
            });
        }
    }
};
