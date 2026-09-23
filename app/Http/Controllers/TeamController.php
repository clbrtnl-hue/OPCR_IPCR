<?php

namespace App\Http\Controllers;

use App\Models\OrgUnit;
use App\Models\PcrForm;
use App\Models\PcrPeriodSummary;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'nullable|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $user = $request->user();
        $year = ($data['school_year_id'] ?? null)
            ? SchoolYear::find($data['school_year_id'])
            : SchoolYear::where('is_active', true)->first() ?? SchoolYear::orderByDesc('start_date')->first();

        if (! $year) {
            return response()->json(['year' => null, 'period' => null, 'units' => [], 'members' => []]);
        }

        $period = ($data['rating_period_id'] ?? null)
            ? RatingPeriod::where('school_year_id', $year->id)->find($data['rating_period_id'])
            : RatingPeriod::where('school_year_id', $year->id)->where('is_active', true)->first()
                ?? RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->first();

        $units = $this->unitsLedBy($user);

        if ($units->isEmpty()) {
            return response()->json([
                'year'    => ['id' => $year->id, 'label' => $year->label],
                'period'  => $period ? ['id' => $period->id, 'label' => $period->label] : null,
                'units'   => [],
                'members' => [],
            ]);
        }

        $members = User::where('status', 'active')
            ->whereIn('org_unit_id', $units->pluck('id'))
            ->where('role', '!=', 'admin')
            ->where('id', '!=', $user->id)
            ->orderByPerson()
            ->get(['id', 'name', 'role', 'position_title', 'image', 'org_unit_id']);

        $forms = PcrForm::where('type', 'ipcr')
            ->where('school_year_id', $year->id)
            ->where(fn ($q) => $period
                ? $q->where('rating_period_id', $period->id)
                : $q->whereNull('rating_period_id'))
            ->whereIn('user_id', $members->pluck('id'))
            ->withCount('outputs')
            ->get()
            ->keyBy('user_id');

        $summaries = PcrPeriodSummary::whereIn('form_id', $forms->pluck('id'))->get()->keyBy('form_id');
        $unitsById = $units->keyBy('id');

        return response()->json([
            'year'    => ['id' => $year->id, 'label' => $year->label],
            'period'  => $period ? ['id' => $period->id, 'label' => $period->label] : null,
            'units'   => $units->map(fn ($unit) => [
                'id'   => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
                'as'   => (int) $unit->head_user_id === (int) $user->id ? 'head' : 'vp',
            ])->values(),
            'members' => $members->map(function ($member) use ($forms, $summaries, $unitsById) {
                $form    = $forms->get($member->id);
                $summary = $form ? $summaries->get($form->id) : null;
                $unit    = $unitsById->get($member->org_unit_id);

                return [
                    'id'             => $member->id,
                    'name'           => $member->name,
                    'role'           => $member->role,
                    'position_title' => $member->position_title,
                    'image'          => $member->image,
                    'org_unit'       => $unit ? ['id' => $unit->id, 'name' => $unit->name, 'code' => $unit->code] : null,
                    'form'           => $form ? [
                        'id'            => $form->id,
                        'status'        => $form->status,
                        'outputs_count' => $form->outputs_count,
                        'submitted_at'  => $form->submitted_at,
                        'average'       => $summary?->final_average !== null ? (float) $summary->final_average : null,
                        'adjectival'    => $summary?->adjectival,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    private function unitsLedBy(User $user)
    {
        $query = OrgUnit::query()->orderBy('name');

        if (! $user->isAdmin()) {
            $query->where(fn ($q) => $q
                ->where('head_user_id', $user->id)
                ->orWhere('vp_user_id', $user->id));
        }

        return $query->get(['id', 'name', 'code', 'head_user_id', 'vp_user_id']);
    }
}
