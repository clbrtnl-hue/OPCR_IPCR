<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The chart order that used to live in the page, kept so existing units do not jump. */
    private const POSTER_ORDER = [
        'SAS' => 1,
        'AAS' => 2,
        'AFS' => 3,
        'RPCES' => 4,
        'ILS' => 5,
        'OP' => 6,
        'OP-CICT' => 1,
        'OP-QAAO' => 2,
        'OP-PCO' => 3,
        'OP-CHRE' => 4,
        'AAS-ASD' => 1,
        'AAS-ASV' => 2,
        'AAS-HEI' => 1,
        'REG' => 1,
        'AAS-TESDA' => 2,
        'REG-AE' => 1,
        'REG-RA' => 2,
        'REG-GC' => 3,
        'AAS-CTE' => 1,
        'AAS-BSBA' => 2,
        'AAS-BSIT' => 3,
        'AAS-CCJ' => 4,
        'AAS-GenEd' => 5,
        'AAS-PE' => 6,
        'SAS-SSS' => 1,
        'SAS-SDS' => 2,
        'SAS-ISPS' => 3,
        'SAS-SEC' => 4,
    ];

    public function up(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0);
        });

        $units = DB::table('org_units')->get(['id', 'parent_id', 'code', 'name']);

        foreach ($units->groupBy(fn ($unit) => $unit->parent_id ?? 0) as $siblings) {
            $sorted = $siblings->sortBy(function ($unit) {
                $order = self::POSTER_ORDER[$unit->code] ?? 100;

                return sprintf('%05d-%s', $order, mb_strtolower((string) $unit->name));
            })->values();

            foreach ($sorted as $index => $unit) {
                DB::table('org_units')->where('id', $unit->id)->update([
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
