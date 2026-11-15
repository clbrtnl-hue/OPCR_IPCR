<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-out notices opened the IPCR without saying which lines they were about,
 * so the sheet looked empty. Point each notice at the lines still assigned
 * from that same hand-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        $notes = DB::table('notifications as n')
            ->join('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.type', 'assignment')
            ->whereNotNull('n.form_id')
            ->where(function ($query) {
                $query->whereNull('n.link')
                    ->orWhere('n.link', 'not like', '%target%');
            })
            ->get(['n.id', 'n.user_id', 'n.title', 'n.body', 'n.form_id', 'n.link', 'n.created_at', 'u.name']);

        foreach ($notes as $note) {
            $ids = $this->indicatorIds($note);

            if ($ids === []) {
                continue;
            }

            $query = count($ids) === 1
                ? 'target=' . $ids[0]
                : 'targets=' . implode(',', $ids);

            $link = '/forms/' . $note->form_id . '?' . $query;

            if (strlen($link) > 255) {
                continue;
            }

            DB::table('notifications')->where('id', $note->id)->update(['link' => $link]);
        }
    }

    public function down(): void
    {
    }

    /** @return array<int, int> */
    private function indicatorIds(object $note): array
    {
        $logged = $this->loggedIndicatorIds($note);
        $alive  = $this->stillAssigned($note->user_id, $logged);

        if ($this->isHandout($note)) {
            if ($alive !== []) {
                return $alive;
            }

            return $this->assignedNear($note);
        }

        if (count($alive) === 1) {
            return $alive;
        }

        $matched = $this->matchBody($note);

        return $matched ? [$matched] : (count($alive) === 1 ? $alive : []);
    }

    private function isHandout(object $note): bool
    {
        return (bool) preg_match('/\d+ commitments/', (string) $note->title);
    }

    /** @return array<int, int> */
    private function loggedIndicatorIds(object $note): array
    {
        $name = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $note->name);
        $at   = strtotime((string) $note->created_at);

        return DB::table('activity_logs')
            ->where('action', 'assign')
            ->where('subject_type', 'PcrIndicator')
            ->where('description', 'like', 'Assigned to ' . $name . ':%')
            ->whereBetween('created_at', [
                date('Y-m-d H:i:s', $at - 3),
                date('Y-m-d H:i:s', $at + 3),
            ])
            ->orderBy('id')
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $indicatorIds
     * @return array<int, int>
     */
    private function stillAssigned(int $userId, array $indicatorIds): array
    {
        if ($indicatorIds === []) {
            return [];
        }

        $alive = DB::table('pcr_target_assignments')
            ->where('user_id', $userId)
            ->whereIn('indicator_id', $indicatorIds)
            ->pluck('indicator_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $alive = array_flip($alive);

        return array_values(array_filter(
            $indicatorIds,
            fn ($id) => isset($alive[$id])
        ));
    }

    /** @return array<int, int> */
    private function assignedNear(object $note): array
    {
        $form = DB::table('pcr_forms')->where('id', $note->form_id)->first();

        if (! $form) {
            return [];
        }

        $at = strtotime((string) $note->created_at);

        return DB::table('pcr_target_assignments as a')
            ->join('pcr_indicators as i', 'i.id', '=', 'a.indicator_id')
            ->join('pcr_outputs as o', 'o.id', '=', 'i.output_id')
            ->join('pcr_forms as src', 'src.id', '=', 'o.form_id')
            ->where('a.user_id', $note->user_id)
            ->where('src.school_year_id', $form->school_year_id)
            ->where('src.id', '!=', $form->id)
            ->whereBetween('a.created_at', [
                date('Y-m-d H:i:s', $at - 5),
                date('Y-m-d H:i:s', $at + 5),
            ])
            ->orderBy('a.id')
            ->pluck('a.indicator_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function matchBody(object $note): ?int
    {
        $lead = $this->lead($note->body);

        if ($lead === '' || str_starts_with($lead, 'They are waiting')) {
            return null;
        }

        $form = DB::table('pcr_forms')->where('id', $note->form_id)->first();

        if (! $form) {
            return null;
        }

        $rows = DB::table('pcr_target_assignments as a')
            ->join('pcr_indicators as i', 'i.id', '=', 'a.indicator_id')
            ->join('pcr_outputs as o', 'o.id', '=', 'i.output_id')
            ->join('pcr_forms as src', 'src.id', '=', 'o.form_id')
            ->where('a.user_id', $note->user_id)
            ->where('src.school_year_id', $form->school_year_id)
            ->get(['a.indicator_id', 'i.description']);

        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $row->description))));

            if ($text !== '' && (str_starts_with($text, $lead) || str_starts_with($lead, mb_substr($text, 0, 80)))) {
                return (int) $row->indicator_id;
            }
        }

        return null;
    }

    private function lead(?string $body): string
    {
        $body = trim((string) $body);

        foreach ([' — ', '—', ' ? '] as $separator) {
            if (str_contains($body, $separator)) {
                return trim(explode($separator, $body, 2)[0]);
            }
        }

        return $body;
    }
};
