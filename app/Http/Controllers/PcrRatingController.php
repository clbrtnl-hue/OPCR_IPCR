<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Support\Html;
use App\Services\RatingScale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PcrRatingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'form_id'              => 'required|integer|exists:pcr_forms,id',
            'rating_period_id'     => 'required|integer|exists:rating_periods,id',
            'ratings'              => 'required|array|min:1',
            'ratings.*.indicator_id' => 'required|integer|exists:pcr_indicators,id',
            'ratings.*.q'          => 'nullable|integer|min:1|max:5',
            'ratings.*.e'          => 'nullable|integer|min:1|max:5',
            'ratings.*.t'          => 'nullable|integer|min:1|max:5',
            'ratings.*.remarks'    => 'nullable|string|max:2000',
        ]);

        $form   = PcrForm::findOrFail($data['form_id']);
        $period = RatingPeriod::findOrFail($data['rating_period_id']);
        $user   = $request->user();

        if ($period->isLockedFor($user)) {
            return response()->json([
                'message' => "{$period->label} is locked. It is view-only now — ask an administrator to unlock it.",
            ], 409);
        }

        if ($form->status !== 'qa_rating') {
            return response()->json([
                'message' => 'This form is not waiting for a rating yet.',
            ], 409);
        }

        if ((int) $period->school_year_id !== (int) $form->school_year_id) {
            return response()->json([
                'message' => 'That review period belongs to a different school year.',
            ], 422);
        }

        if ($form->rating_period_id && (int) $form->rating_period_id !== (int) $period->id) {
            return response()->json([
                'message' => 'This IPCR covers a different review period.',
            ], 422);
        }

        $formIndicatorIds = $this->indicatorIds($form);

        foreach ($data['ratings'] as $row) {
            if (! in_array((int) $row['indicator_id'], $formIndicatorIds, true)) {
                return response()->json([
                    'message' => 'One of those indicators is not part of this form.',
                ], 422);
            }
        }

        DB::transaction(function () use ($data, $period, $user) {
            foreach ($data['ratings'] as $row) {
                $q = $row['q'] ?? null;
                $e = $row['e'] ?? null;
                $t = $row['t'] ?? null;

                PcrRating::updateOrCreate(
                    [
                        'indicator_id'     => $row['indicator_id'],
                        'rating_period_id' => $period->id,
                    ],
                    [
                        'q'             => $q,
                        'e'             => $e,
                        't'             => $t,
                        'a'             => RatingScale::average($q, $e, $t),
                        'remarks' => Html::clean($row['remarks'] ?? null),
                        'rated_by'      => $user->id,
                        'rated_by_name' => $user->name,
                    ]
                );
            }
        });

        return response()->json([
            'data'    => 'saved',
            'summary' => $this->computeSummary($form, $period),
        ]);
    }

    public function finalize(Request $request, $id)
    {
        $data = $request->validate([
            'rating_period_id' => 'required|integer|exists:rating_periods,id',
        ]);

        $form   = PcrForm::findOrFail($id);
        $period = RatingPeriod::findOrFail($data['rating_period_id']);
        $user   = $request->user();

        if ($period->isLockedFor($user)) {
            return response()->json([
                'message' => "{$period->label} is locked. It is view-only now — ask an administrator to unlock it.",
            ], 409);
        }

        if ($form->status !== 'qa_rating') {
            return response()->json(['message' => 'This form is not waiting for a rating.'], 409);
        }

        if ($form->rating_period_id && (int) $form->rating_period_id !== (int) $period->id) {
            return response()->json([
                'message' => 'This IPCR covers a different review period.',
            ], 422);
        }

        $summary = $this->computeSummary($form, $period);

        if ($summary['rated_indicators'] < $summary['total_indicators']) {
            $missing = $summary['total_indicators'] - $summary['rated_indicators'];

            return response()->json([
                'message' => "{$missing} indicator(s) still have no rating. Rate every line before finalizing.",
            ], 422);
        }

        DB::transaction(function () use ($form, $period, $summary, $user) {
            PcrPeriodSummary::updateOrCreate(
                ['form_id' => $form->id, 'rating_period_id' => $period->id],
                [
                    'strategic_average' => $summary['strategic_average'],
                    'core_average'      => $summary['core_average'],
                    'support_average'   => $summary['support_average'],
                    'final_average'     => $summary['final_average'],
                    'adjectival'        => $summary['adjectival'],
                    'rated_indicators'  => $summary['rated_indicators'],
                    'total_indicators'  => $summary['total_indicators'],
                ]
            );

            $form->update([
                'status'        => 'rated',
                'rated_by'      => $user->id,
                'rated_by_name' => $user->name,
                'rated_at'      => now(),
            ]);

            PcrStatusLog::record(
                $form->id,
                'qa_rating',
                'rated',
                "{$period->label}: {$summary['final_average']} ({$summary['adjectival']})"
            );
        });

        ActivityLog::record(
            'PcrForm',
            $form->id,
            'rate',
            "Rated {$summary['final_average']} — {$summary['adjectival']}"
        );

        $form->loadMissing('orgUnit');

        Notification::sendMany(
            [$form->user_id, $form->orgUnit?->head_user_id, $form->orgUnit?->vp_user_id],
            'rated',
            strtoupper($form->type) . ' rating is out',
            "{$period->label}: {$summary['final_average']} — {$summary['adjectival']}",
            $form->id
        );

        return response()->json(['data' => 'finalized', 'summary' => $summary, 'form' => $form->fresh()]);
    }

    private function indicatorIds(PcrForm $form): array
    {
        return PcrIndicator::whereHas('output', fn ($q) => $q->where('form_id', $form->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function computeSummary(PcrForm $form, RatingPeriod $period): array
    {
        $rows = PcrIndicator::query()
            ->join('pcr_outputs', 'pcr_indicators.output_id', '=', 'pcr_outputs.id')
            ->leftJoin('pcr_ratings', function ($join) use ($period) {
                $join->on('pcr_ratings.indicator_id', '=', 'pcr_indicators.id')
                    ->where('pcr_ratings.rating_period_id', '=', $period->id);
            })
            ->where('pcr_outputs.form_id', $form->id)
            ->get(['pcr_indicators.id', 'pcr_outputs.section', 'pcr_ratings.a']);

        $bySection = ['strategic' => [], 'core' => [], 'support' => []];
        $rated     = 0;

        foreach ($rows as $row) {
            $value = $row->a === null ? null : (float) $row->a;

            if ($value !== null) {
                $rated++;
            }

            $bySection[$row->section][] = $value;
        }

        $sectionAverages = [
            'strategic' => RatingScale::mean($bySection['strategic']),
            'core'      => RatingScale::mean($bySection['core']),
            'support'   => RatingScale::mean($bySection['support']),
        ];

        $final = RatingScale::mean(array_values($sectionAverages));

        return [
            'strategic_average' => $sectionAverages['strategic'],
            'core_average'      => $sectionAverages['core'],
            'support_average'   => $sectionAverages['support'],
            'final_average'     => $final,
            'adjectival'        => RatingScale::adjectival($final),
            'rated_indicators'  => $rated,
            'total_indicators'  => $rows->count(),
        ];
    }
}
