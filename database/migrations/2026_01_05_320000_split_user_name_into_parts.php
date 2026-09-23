<?php

use App\Support\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('prefix', 20)->nullable()->after('name');
            $table->string('first_name', 80)->nullable()->after('prefix');
            $table->string('middle_initial', 10)->nullable()->after('first_name');
            $table->string('last_name', 80)->nullable()->after('middle_initial');
            $table->string('suffix', 20)->nullable()->after('last_name');
            $table->string('credentials', 60)->nullable()->after('suffix');

            $table->index('last_name');
        });

        DB::table('users')->select('id', 'name')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->id)->update(PersonName::parse($row->name));
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_name']);
            $table->dropColumn([
                'prefix', 'first_name', 'middle_initial', 'last_name', 'suffix', 'credentials',
            ]);
        });
    }
};
