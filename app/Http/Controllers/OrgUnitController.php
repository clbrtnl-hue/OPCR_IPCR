<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\OrgUnit;
use App\Models\OrgUnitReviewer;
use App\Models\OrgUnitViewer;
use App\Models\PcrForm;
use App\Models\User;
use App\Services\WorkflowSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrgUnitController extends Controller
{
    public function index()
    {
        $units = OrgUnit::with([
                'head:id,name,position_title,image',
                'dean:id,name,position_title,image',
                'vp:id,name,position_title,image',
                'reviewers.user:id,name,position_title,image',
                'viewers.user:id,name',
            ])
            ->withCount('members')
            ->orderBy('name')
            ->get();

        $rolled = OrgUnit::rolledMemberCounts($units);

        $units->each(function (OrgUnit $unit) use ($rolled) {
            $unit->direct_members_count = (int) $unit->members_count;
            $unit->members_count = $rolled[(int) $unit->id] ?? $unit->direct_members_count;
        });

        return response()->json($units);
    }

    public function store(Request $request)
    {
        $id = $request->input('id');

        $data = $request->validate([
            'id'           => 'nullable|integer|exists:org_units,id',
            'name'         => 'required|string|max:150',
            'code'         => 'nullable|string|max:30',
            'type'         => ['required', Rule::in(['college', 'office', 'program'])],
            'parent_id'    => 'nullable|integer|exists:org_units,id',
            'head_user_id' => 'nullable|integer|exists:users,id',
            'dean_user_id' => 'nullable|integer|exists:users,id',
            'vp_user_id'   => 'nullable|integer|exists:users,id',
            'reviewers'    => 'nullable|array',
            'reviewers.*'  => 'nullable|integer|exists:users,id',
            'viewers'         => 'nullable|array',
            'viewers.*'       => 'integer|exists:users,id',
            'chart_user_ids'  => 'nullable|array|max:'.OrgUnit::CHART_LIMIT,
            'chart_user_ids.*'=> 'integer|exists:users,id',
        ]);

        if ($id && ! empty($data['parent_id']) && (int) $data['parent_id'] === (int) $id) {
            return response()->json(['message' => 'A unit cannot be its own parent.'], 422);
        }

        $attributes = [
            'name'         => $data['name'],
            'code'         => $data['code'] ?? null,
            'type'         => $data['type'],
            'parent_id'    => $data['parent_id'] ?? null,
            'head_user_id' => $data['head_user_id'] ?? null,
            'dean_user_id' => $data['dean_user_id'] ?? null,
            'vp_user_id'   => $data['vp_user_id'] ?? null,
        ];

        if ($id) {
            $unit    = OrgUnit::findOrFail($id);
            $changes = ActivityLog::diff($unit, $attributes);
            $unit->update($attributes);

            ActivityLog::record('OrgUnit', $unit->id, 'update', "Updated unit {$unit->name}", $changes);
            $this->syncReviewers($unit, $data['reviewers'] ?? null);
            $this->syncViewers($unit, $data['viewers'] ?? null);
            $this->syncChartPerson($unit, $data);

            return response()->json(['data' => 'updated', 'unit' => $unit->load(['head', 'dean', 'vp', 'reviewers.user', 'viewers.user'])]);
        }

        $unit = OrgUnit::create($attributes);
        $this->syncReviewers($unit, $data['reviewers'] ?? null);
        $this->syncViewers($unit, $data['viewers'] ?? null);
        $this->syncChartPerson($unit, $data);

        ActivityLog::record('OrgUnit', $unit->id, 'create', "Created unit {$unit->name}");

        return response()->json(['data' => 'created', 'unit' => $unit->load(['head', 'dean', 'vp', 'reviewers.user', 'viewers.user'])], 201);
    }

    /** Extra steps name their reviewer here. Head, dean, and VP are saved on the unit and stored in the same reviewer list. */
    private function syncReviewers(OrgUnit $unit, ?array $reviewers): void
    {
        if ($reviewers === null) {
            return;
        }

        $slots = collect(app(WorkflowSettings::class)->reviewStages())
            ->filter(fn ($stage) => ($stage['source'] ?? 'unit_slot') === 'unit_slot')
            ->pluck('slot')
            ->filter()
            ->all();

        foreach ($reviewers as $slot => $userId) {
            if (! in_array($slot, $slots, true) || in_array($slot, ['head_user_id', 'dean_user_id', 'vp_user_id'], true)) {
                continue;
            }

            OrgUnitReviewer::updateOrCreate(
                ['org_unit_id' => $unit->id, 'slot' => $slot],
                ['user_id' => $userId]
            );
        }
    }

    /** People drawn on the chart. Absent leaves the current list. They are not reviewers. */
    private function syncChartPerson(OrgUnit $unit, array $data): void
    {
        if (! array_key_exists('chart_user_ids', $data)) {
            return;
        }

        $ids = collect($data['chart_user_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(OrgUnit::CHART_LIMIT)
            ->values();

        OrgUnitReviewer::query()
            ->where('org_unit_id', $unit->id)
            ->where('slot', 'like', 'chart_display%')
            ->delete();

        foreach ($ids as $index => $id) {
            OrgUnitReviewer::create([
                'org_unit_id' => $unit->id,
                'slot'        => 'chart_display_'.($index + 1),
                'user_id'     => $id,
            ]);
        }
    }

    /** Replace who may read this unit. Absent means the caller did not touch the list. */
    private function syncViewers(OrgUnit $unit, ?array $viewers): void
    {
        if ($viewers === null) {
            return;
        }

        $ids = collect($viewers)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $unit->viewers()->when(
            $ids->isNotEmpty(),
            fn ($query) => $query->whereNotIn('user_id', $ids)
        )->delete();

        foreach ($ids as $id) {
            OrgUnitViewer::firstOrCreate([
                'org_unit_id' => $unit->id,
                'user_id'     => $id,
            ]);
        }
    }

    /** Offices where this person is named on a review slot, for the menu. */
    public function leading(Request $request)
    {
        $units = OrgUnit::query()
            ->whereLedBy($request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($units);
    }

    /** Units this person may open, for the menu. The review chain is not involved. */
    public function watching(Request $request)
    {
        $ids = OrgUnit::watchedIds((int) $request->user()->id);

        $units = $ids === []
            ? collect()
            : OrgUnit::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);

        return response()->json($units);
    }

    public function destroy($id)
    {
        $unit = OrgUnit::withCount(['members', 'children'])->findOrFail($id);
        $parentId = $unit->parent_id ? (int) $unit->parent_id : null;
        $hasForms = PcrForm::where('org_unit_id', $unit->id)->exists();

        if ($unit->members_count > 0) {
            return response()->json([
                'message' => 'This unit still has people. Move them out before deleting it.',
            ], 409);
        }

        if (! $parentId && ($unit->children_count > 0 || $hasForms)) {
            return response()->json([
                'message' => 'The top unit stays while it still has people or sub-units.',
            ], 409);
        }

        if ($parentId && $this->formsWouldCollide($unit->id, $parentId)) {
            return response()->json([
                'message' => 'This unit has a form for a period that the parent unit already has. Move that form first.',
            ], 409);
        }

        $name = $unit->name;

        DB::transaction(function () use ($unit, $parentId) {
            if ($parentId) {
                OrgUnit::where('parent_id', $unit->id)->update(['parent_id' => $parentId]);
                User::where('org_unit_id', $unit->id)->update(['org_unit_id' => $parentId]);
                PcrForm::where('org_unit_id', $unit->id)->update(['org_unit_id' => $parentId]);
            }

            $unit->delete();
        });

        ActivityLog::record('OrgUnit', (int) $id, 'delete', "Deleted unit {$name}");

        return response()->json(['data' => 'deleted']);
    }

    /** A form cannot sit on the parent when that person already has one there for the same period. */
    private function formsWouldCollide(int $unitId, int $parentId): bool
    {
        $forms = PcrForm::where('org_unit_id', $unitId)->get([
            'id', 'type', 'school_year_id', 'user_id', 'rating_period_id',
        ]);

        foreach ($forms as $form) {
            $clash = PcrForm::query()
                ->where('type', $form->type)
                ->where('school_year_id', $form->school_year_id)
                ->where('org_unit_id', $parentId)
                ->where('id', '!=', $form->id)
                ->where(function ($query) use ($form) {
                    $form->user_id === null
                        ? $query->whereNull('user_id')
                        : $query->where('user_id', $form->user_id);
                })
                ->where(function ($query) use ($form) {
                    $form->rating_period_id === null
                        ? $query->whereNull('rating_period_id')
                        : $query->where('rating_period_id', $form->rating_period_id);
                })
                ->exists();

            if ($clash) {
                return true;
            }
        }

        return false;
    }
}
