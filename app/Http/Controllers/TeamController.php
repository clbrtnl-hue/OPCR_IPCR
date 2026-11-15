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

        $mayOpen = in_array($user->role, ['program_head', 'vp', 'qa'], true)
            || OrgUnit::watchedIds((int) $user->id) !== [];

        if (! $mayOpen) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

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

        $units = $this->unitsFor($user);

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
                'as'   => $this->capacityOn($unit, $user),
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

    private function unitsFor(User $user)
    {
        $query = OrgUnit::query()->with(['reviewers', 'viewers'])->orderBy('name');

        if ($user->isAdmin()) {
            return $query->get(['id', 'name', 'code']);
        }

        $ids = array_values(array_unique(array_merge(
            OrgUnit::query()->whereLedBy($user->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            OrgUnit::watchedIds((int) $user->id)
        )));

        if ($ids === []) {
            return $query->whereRaw('0 = 1')->get(['id', 'name', 'code']);
        }

        return $query->whereIn('id', $ids)->get(['id', 'name', 'code']);
    }

    private function capacityOn(OrgUnit $unit, User $user): string
    {
        $held = ($unit->relationLoaded('reviewers') ? $unit->reviewers : $unit->reviewers()->get())
            ->filter(fn ($row) => (int) $row->user_id === (int) $user->id)
            ->pluck('slot');

        foreach ([
            'head_user_id'    => 'head',
            'dean_user_id'    => 'dean',
            'vp_user_id'      => 'vp',
            'director_review' => 'director',
        ] as $slot => $label) {
            if ($held->contains($slot)) {
                return $label;
            }
        }

        $views = $unit->relationLoaded('viewers') ? $unit->viewers : $unit->viewers()->get();

        if ($views->contains(fn ($row) => (int) $row->user_id === (int) $user->id)) {
            return 'viewer';
        }

        return 'vp';
    }
}
