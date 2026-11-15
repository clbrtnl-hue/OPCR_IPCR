<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A hand-out used to link the assignee to the source form. That is the OPCR
 * (or somebody else's IPCR), which they cannot open. Point each of those
 * notices at the assignee's own IPCR for the period the work was handed out.
 */
return new class extends Migration
{
    public function up(): void
    {
        $notes = DB::table('notifications as n')
            ->join('pcr_forms as src', 'src.id', '=', 'n.form_id')
            ->where('n.type', 'assignment')
            ->where(function ($query) {
                $query->where('src.type', '!=', 'ipcr')
                    ->orWhereColumn('src.user_id', '!=', 'n.user_id');
            })
            ->get([
                'n.id',
                'n.user_id',
                'n.created_at',
                'n.form_id',
                'src.school_year_id',
                'src.rating_period_id',
            ]);

        foreach ($notes as $note) {
            $ipcrId = $this->ipcrId($note);

            if (! $ipcrId || (int) $ipcrId === (int) $note->form_id) {
                continue;
            }

            DB::table('notifications')->where('id', $note->id)->update([
                'form_id' => $ipcrId,
                'link'    => '/forms/' . $ipcrId,
            ]);
        }
    }

    public function down(): void
    {
    }

    private function ipcrId(object $note): ?int
    {
        $assigned = DB::table('pcr_target_assignments as a')
            ->join('pcr_indicators as i', 'i.id', '=', 'a.indicator_id')
            ->join('pcr_outputs as o', 'o.id', '=', 'i.output_id')
            ->where('o.form_id', $note->form_id)
            ->where('a.user_id', $note->user_id)
            ->get(['a.rating_period_id', 'a.created_at']);

        $periodId = $note->rating_period_id;

        if ($assigned->isNotEmpty()) {
            $notedAt = strtotime((string) $note->created_at);
            $closest = $assigned->sortBy(
                fn ($row) => abs(strtotime((string) $row->created_at) - $notedAt)
            )->first();
            $periodId = $closest->rating_period_id;
        }

        $id = DB::table('pcr_forms')
            ->where('type', 'ipcr')
            ->where('user_id', $note->user_id)
            ->where('school_year_id', $note->school_year_id)
            ->where('rating_period_id', $periodId)
            ->value('id');

        return $id ? (int) $id : null;
    }
};
