<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR closes an office only after the college OPCR has a rating and every
 * IPCR in that office, for both periods, is already rated. The mean of those
 * IPCRs must not sit above the OPCR.
 */
class HrClosing
{
    public function snapshot(?int $schoolYearId): array
    {
        $year = $this->year($schoolYearId);

        if (! $year) {
            return ['school_year' => null, 'opcr' => null, 'offices' => []];
        }

        $rated = $this->ratedOpcr($year->id);
        $periods = RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->get();
        $forms = PcrForm::with(['owner', 'orgUnit', 'ratingPeriod', 'summaries'])
            ->where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->orderBy('id')
            ->get();

        $offices = $forms
            ->groupBy('org_unit_id')
            ->filter(fn (Collection $group) => $group->contains(fn (PcrForm $form) => $form->status !== 'final'))
            ->map(fn (Collection $group) => $this->officePayload($group, $periods, $rated))
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'school_year' => ['id' => $year->id, 'label' => $year->label],
            'opcr'        => $rated['card'] ?? $this->unratedOpcrCard($year->id),
            'offices'     => $offices,
        ];
    }

    /** Why this office cannot be closed. Null when HR may close it. */
    public function blockOffice(int $orgUnitId, int $schoolYearId): ?string
    {
        $year = SchoolYear::find($schoolYearId);

        if (! $year) {
            return 'That school year does not exist.';
        }

        $forms = PcrForm::with(['summaries', 'ratingPeriod', 'orgUnit', 'owner'])
            ->where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->where('org_unit_id', $orgUnitId)
            ->get();

        if ($forms->isEmpty()) {
            return 'That office has no IPCRs for this year.';
        }

        $payload = $this->officePayload(
            $forms,
            RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->get(),
            $this->ratedOpcr($year->id)
        );

        return $payload['can_close'] ? null : ($payload['reason'] ?? 'This office cannot be closed yet.');
    }

    public function closeOffice(int $orgUnitId, int $schoolYearId): int
    {
        $forms = PcrForm::query()
            ->where('type', 'ipcr')
            ->where('school_year_id', $schoolYearId)
            ->where('org_unit_id', $orgUnitId)
            ->where('status', 'rated')
            ->get();

        DB::transaction(function () use ($forms) {
            foreach ($forms as $form) {
                $form->update(['status' => 'final']);
                PcrStatusLog::record($form->id, 'rated', 'final', 'Closed by HR');
                ActivityLog::record('PcrForm', $form->id, 'status', 'Form moved from rated to final');
            }
        });

        foreach ($forms as $form) {
            Notification::send(
                $form->user_id,
                'rated',
                'Your IPCR is final',
                'HR closed it. The rating can no longer be changed.',
                $form->id
            );
        }

        return $forms->count();
    }

    private function year(?int $schoolYearId): ?SchoolYear
    {
        if ($schoolYearId) {
            return SchoolYear::find($schoolYearId);
        }

        return SchoolYear::where('is_active', true)->orderByDesc('id')->first()
            ?? SchoolYear::orderByDesc('id')->first();
    }

    /**
     * @return array{score: float, card: array<string, mixed>}|null
     */
    private function ratedOpcr(int $yearId): ?array
    {
        $opcr = PcrForm::with('summaries')
            ->where('type', 'opcr')
            ->where('school_year_id', $yearId)
            ->orderByDesc('id')
            ->first();

        if (! $opcr || ! in_array($opcr->status, ['rated', 'final'], true)) {
            return null;
        }

        $score = $this->score($opcr);

        if ($score === null) {
            return null;
        }

        return [
            'score' => $score,
            'card'  => [
                'id'            => $opcr->id,
                'status'        => $opcr->status,
                'final_average' => $score,
                'adjectival'    => $this->adjectival($opcr),
                'has_rating'    => true,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function unratedOpcrCard(int $yearId): ?array
    {
        $opcr = PcrForm::where('type', 'opcr')->where('school_year_id', $yearId)->orderByDesc('id')->first();

        if (! $opcr) {
            return null;
        }

        return [
            'id'            => $opcr->id,
            'status'        => $opcr->status,
            'final_average' => null,
            'adjectival'    => null,
            'has_rating'    => false,
        ];
    }

    /**
     * @param  Collection<int, PcrForm>  $forms
     * @param  Collection<int, RatingPeriod>  $periods
     * @param  array{score: float, card: array<string, mixed>}|null  $rated
     * @return array<string, mixed>
     */
    private function officePayload(Collection $forms, Collection $periods, ?array $rated): array
    {
        $open = 0;
        $waiting = 0;
        $scored = [];

        $rows = $forms->map(function (PcrForm $form) use (&$open, &$waiting, &$scored) {
            $score = $this->score($form);
            $done = in_array($form->status, ['rated', 'final'], true);

            if (! $done) {
                $open++;
            }

            if ($form->status === 'rated') {
                $waiting++;
            }

            if ($done && $score !== null) {
                $scored[] = $score;
            }

            return [
                'id'            => $form->id,
                'owner'         => $form->owner?->name,
                'period'        => $form->ratingPeriod?->label,
                'period_seq'    => (int) ($form->ratingPeriod?->seq ?? 0),
                'status'        => $form->status,
                'final_average' => $score,
                'adjectival'    => $done ? $this->adjectival($form) : null,
            ];
        })->sortBy(fn (array $row) => ($row['owner'] ?? '').' '.$row['period_seq'])->values();

        $missing = 0;
        $periodIds = $periods->pluck('id');

        if ($periodIds->isNotEmpty()) {
            foreach ($forms->groupBy('user_id') as $userForms) {
                $covered = $userForms->pluck('rating_period_id')->filter()->unique();

                if ($periodIds->diff($covered)->isNotEmpty()) {
                    $missing++;
                }
            }
        }

        $average = $scored === [] ? null : round(array_sum($scored) / count($scored), 2);
        $complete = $rated !== null && $open === 0 && $missing === 0 && $average !== null;
        $over = $complete && $average > $rated['score'];

        if ($rated === null) {
            $reason = 'Waiting for the college OPCR rating.';
            $canClose = false;
        } elseif ($open > 0) {
            $reason = $open === 1 ? '1 IPCR is still in review.' : "{$open} IPCRs are still in review.";
            $canClose = false;
        } elseif ($missing > 0) {
            $reason = $missing === 1
                ? '1 person does not have an IPCR for every period.'
                : "{$missing} people do not have an IPCR for every period.";
            $canClose = false;
        } elseif ($over) {
            $reason = 'The office average is above the college OPCR.';
            $canClose = false;
        } elseif ($waiting === 0) {
            $reason = 'These forms are already final.';
            $canClose = false;
        } else {
            $reason = null;
            $canClose = true;
        }

        return [
            'id'        => (int) $forms->first()->org_unit_id,
            'name'      => $forms->first()->orgUnit?->name ?? 'Office',
            'average'   => $average,
            'scored'    => count($scored),
            'total'     => $forms->count(),
            'over'      => $over,
            'can_close' => $canClose,
            'reason'    => $reason,
            'forms'     => $rows->all(),
        ];
    }

    private function score(PcrForm $form): ?float
    {
        $summary = $this->summary($form);

        return $summary?->final_average !== null ? round((float) $summary->final_average, 2) : null;
    }

    private function adjectival(PcrForm $form): ?string
    {
        return $this->summary($form)?->adjectival;
    }

    private function summary(PcrForm $form): ?\App\Models\PcrPeriodSummary
    {
        $summaries = $form->summaries;
        $match = $form->rating_period_id
            ? $summaries->firstWhere('rating_period_id', (int) $form->rating_period_id)
            : null;

        return $match ?? $summaries->sortByDesc('id')->first();
    }
}
