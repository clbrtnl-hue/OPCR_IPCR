<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\OrgUnit;
use App\Models\OrgUnitReviewer;
use App\Services\WorkflowSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgUnitController extends Controller
{
    public function index()
    {
        $units = OrgUnit::with([
                'head:id,name,position_title',
                'dean:id,name,position_title',
                'vp:id,name,position_title',
                'reviewers.user:id,name,position_title',
            ])
            ->withCount('members')
            ->orderBy('name')
            ->get();

        $rolled = OrgUnit::rolledMemberCounts($units);

        $units->each(function (OrgUnit $unit) use ($rolled) {
            $unit->members_count = $rolled[(int) $unit->id] ?? (int) $unit->members_count;
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

            return response()->json(['data' => 'updated', 'unit' => $unit->load(['head', 'dean', 'vp', 'reviewers.user'])]);
        }

        $unit = OrgUnit::create($attributes);
        $this->syncReviewers($unit, $data['reviewers'] ?? null);

        ActivityLog::record('OrgUnit', $unit->id, 'create', "Created unit {$unit->name}");

        return response()->json(['data' => 'created', 'unit' => $unit->load(['head', 'dean', 'vp', 'reviewers.user'])], 201);
    }

    /** Extra steps name their reviewer here. The three built-in slots stay on the unit columns. */
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

    public function destroy($id)
    {
        $unit = OrgUnit::withCount(['members', 'children'])->findOrFail($id);

        if ($unit->members_count > 0 || $unit->children_count > 0) {
            return response()->json([
                'message' => 'Move the people and sub-units out of this unit before deleting it.',
            ], 409);
        }

        $name = $unit->name;
        $unit->delete();

        ActivityLog::record('OrgUnit', (int) $id, 'delete', "Deleted unit {$name}");

        return response()->json(['data' => 'deleted']);
    }
}
