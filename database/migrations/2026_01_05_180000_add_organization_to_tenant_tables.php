<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hangs the tenant-root tables off `organizations`. Everything else inherits
 * isolation transitively (rating_periods -> school_years, outputs -> forms, and
 * so on); `pcr_forms` carries a denormalised copy because it is the table the
 * queues and reports scan most.
 *
 * Existing rows belong to the first organization, which the seeder creates as
 * Opol Community College.
 */
return new class extends Migration
{
    private const TABLES = ['users', 'org_units', 'school_years', 'pcr_forms'];

    public function up(): void
    {
        $first = DB::table('organizations')->min('id');

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            });

            if ($first) {
                DB::table($name)->update(['organization_id' => $first]);
            }

            Schema::table($name, function (Blueprint $table) {
                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->index('organization_id');
            });
        }

        // Two organizations will each have a "2026"; the label is only unique within one.
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropUnique('school_years_label_unique');
            $table->unique(['organization_id', 'label'], 'school_years_org_label_unique');
        });
    }

    public function down(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropUnique('school_years_org_label_unique');
            $table->unique('label');
        });

        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign($name . '_organization_id_foreign');
                $table->dropIndex($name . '_organization_id_index');
                $table->dropColumn('organization_id');
            });
        }
    }
};
