<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\OrgUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgUnitController extends Controller
{
    public function index()
    {
        return response()->json(
            OrgUnit::with(['head:id,name,position_title', 'vp:id,name,position_title'])
                ->withCount('members')
                ->orderBy('name')
                ->get()
        );
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
            'vp_user_id'   => 'nullable|integer|exists:users,id',
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
            'vp_user_id'   => $data['vp_user_id'] ?? null,
        ];

        if ($id) {
            $unit    = OrgUnit::findOrFail($id);
            $changes = ActivityLog::diff($unit, $attributes);
            $unit->update($attributes);

            ActivityLog::record('OrgUnit', $unit->id, 'update', "Updated unit {$unit->name}", $changes);

            return response()->json(['data' => 'updated', 'unit' => $unit->load(['head', 'vp'])]);
        }

        $unit = OrgUnit::create($attributes);

        ActivityLog::record('OrgUnit', $unit->id, 'create', "Created unit {$unit->name}");

        return response()->json(['data' => 'created', 'unit' => $unit->load(['head', 'vp'])], 201);
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
