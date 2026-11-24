<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Appeal is no longer a step. A form that was waiting on the President
 * keeps the rating QA already finalized.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('pcr_forms')->where('status', 'appeal')->pluck('id');

        foreach ($ids as $id) {
            DB::table('pcr_forms')->where('id', $id)->update([
                'status'     => 'final',
                'updated_at' => now(),
            ]);

            DB::table('pcr_status_logs')->insert([
                'form_id'           => $id,
                'from_status'       => 'appeal',
                'to_status'         => 'final',
                'note'              => 'Appeal was removed. The rating stays final.',
                'performed_by'      => null,
                'performed_by_name' => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The appeal step is gone. These forms stay final.
    }
};
