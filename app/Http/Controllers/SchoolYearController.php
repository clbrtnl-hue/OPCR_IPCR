<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolYearController extends Controller
{
    public function index()
    {
        return response()->json(
            SchoolYear::with('periods')->orderByDesc('start_date')->get()
        );
    }

    public function store(Request $request)
    {
        $id = $request->input('id');

        $data = $request->validate([
            'id'                     => 'nullable|integer|exists:school_years,id',
            'label'                  => ['required', 'string', 'max:60', Rule::unique('school_years')->ignore($id)],
            'start_date'             => 'required|date',
            'end_date'               => 'required|date|after:start_date',
            'status'                 => ['nullable', Rule::in(['open', 'closed'])],
            'is_active'              => 'nullable|boolean',
            'periods'                => 'nullable|array|size:2',
            'periods.*.label'        => 'required_with:periods|string|max:60',
            'periods.*.opens_at'     => 'nullable|date',
            'periods.*.closes_at'    => 'nullable|date',
        ]);

        return DB::transaction(function () use ($data, $id) {
            $attributes = [
                'label'      => $data['label'],
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
                'status'     => $data['status'] ?? 'open',
            ];

            if ($id) {
                $year    = SchoolYear::findOrFail($id);
                $changes = ActivityLog::diff($year, $attributes);
                $year->update($attributes);
                ActivityLog::record('SchoolYear', $year->id, 'update', "Updated {$year->label}", $changes);
            } else {
                $year = SchoolYear::create($attributes);
                ActivityLog::record('SchoolYear', $year->id, 'create', "Created {$year->label}");
            }

            $periods = $data['periods'] ?? [
                ['label' => 'Mid-year Review', 'opens_at' => null, 'closes_at' => null],
                ['label' => 'End-year Review', 'opens_at' => null, 'closes_at' => null],
            ];

            foreach (array_values($periods) as $index => $period) {
                RatingPeriod::updateOrCreate(
                    ['school_year_id' => $year->id, 'seq' => $index + 1],
                    [
                        'label'     => $period['label'],
                        'opens_at'  => $period['opens_at'] ?? null,
                        'closes_at' => $period['closes_at'] ?? null,
                    ]
                );
            }

            if ($data['is_active'] ?? false) {
                $this->activateYear($year);
            }

            return response()->json([
                'data'        => $id ? 'updated' : 'created',
                'school_year' => $year->fresh('periods'),
            ], $id ? 200 : 201);
        });
    }

    public function activate($id)
    {
        $year = SchoolYear::findOrFail($id);

        DB::transaction(fn () => $this->activateYear($year));

        ActivityLog::record('SchoolYear', $year->id, 'activate', "Set {$year->label} as the active school year");

        return response()->json(['data' => 'activated', 'school_year' => $year->fresh('periods')]);
    }

    public function activatePeriod($id)
    {
        $period = RatingPeriod::with('schoolYear')->findOrFail($id);

        DB::transaction(function () use ($period) {
            RatingPeriod::query()
                ->whereHas('schoolYear')
                ->whereKeyNot($period->id)
                ->update(['is_active' => false]);
            $period->update(['is_active' => true, 'status' => 'open']);
            $this->activateYear($period->schoolYear);
        });

        ActivityLog::record(
            'RatingPeriod',
            $period->id,
            'activate',
            "Set {$period->label} of {$period->schoolYear->label} as the active review period"
        );

        return response()->json(['data' => 'activated', 'period' => $period->fresh()]);
    }

    public function setPeriodStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['upcoming', 'open', 'closed'])],
        ]);

        $period = RatingPeriod::with('schoolYear')->findOrFail($id);
        $from   = $period->status;
        $period->update(['status' => $data['status']]);

        ActivityLog::record(
            'RatingPeriod',
            $period->id,
            'status',
            "{$period->label} moved from {$from} to {$data['status']}"
        );

        return response()->json(['data' => 'updated', 'period' => $period->fresh()]);
    }

    /**
     * The cut-off. A locked period is view-only for everyone but an
     * administrator — no commitments, accomplishments, evidence or ratings.
     */
    public function setPeriodLock(Request $request, $id)
    {
        $data = $request->validate([
            'locked' => 'required|boolean',
        ]);

        $period = RatingPeriod::with('schoolYear')->findOrFail($id);
        $user   = $request->user();
        $lock   = (bool) $data['locked'];

        if ($lock === (bool) $period->is_locked) {
            return response()->json([
                'message' => $lock
                    ? "{$period->label} is already locked."
                    : "{$period->label} is not locked.",
            ], 409);
        }

        $period->update([
            'is_locked'      => $lock,
            'locked_at'      => $lock ? now() : null,
            'locked_by'      => $lock ? $user->id : null,
            'locked_by_name' => $lock ? $user->name : null,
        ]);

        ActivityLog::record(
            'RatingPeriod',
            $period->id,
            $lock ? 'lock' : 'unlock',
            $lock ? "{$period->label} locked — view only" : "{$period->label} unlocked"
        );

        return response()->json(['data' => 'updated', 'period' => $period->fresh()]);
    }

    private function activateYear(SchoolYear $year): void
    {
        SchoolYear::query()->whereKeyNot($year->id)->update(['is_active' => false]);
        $year->update(['is_active' => true]);

        // The green "Active" tag on a review point is independent of the year
        // flag. Leaving 2026's period active after switching to 2027 still
        // presents 2026 as the current cycle.
        RatingPeriod::query()
            ->whereHas('schoolYear', fn ($query) => $query->whereKeyNot($year->id))
            ->update(['is_active' => false]);

        if (! $year->periods()->where('is_active', true)->exists()) {
            $year->periods()->orderBy('seq')->first()?->update(['is_active' => true]);
        }
    }
}
