<?php

namespace App\Http\Controllers;

use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrTargetReplacement;
use App\Services\TargetReplacementService;
use App\Support\Html;
use Illuminate\Http\Request;

class TargetReplacementController extends Controller
{
    public function store(Request $request, TargetReplacementService $replacements)
    {
        $data = $request->validate([
            'indicator_id'    => 'required|integer|exists:pcr_indicators,id',
            'description'     => ['required', 'string', Html::maxChars(500)],
            'target_date'     => 'nullable|date',
            'allotted_budget' => 'nullable|numeric|min:0',
            'user_ids'        => 'sometimes|array',
            'user_ids.*'      => 'integer|exists:users,id',
        ]);

        $indicator = PcrIndicator::with('output.form')->findOrFail($data['indicator_id']);

        try {
            $replacement = $replacements->propose($request->user(), $indicator, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data'           => 'proposed',
            'replacement_id' => $replacement->id,
        ], 201);
    }

    public function storeMany(Request $request, TargetReplacementService $replacements)
    {
        $data = $request->validate([
            'lines'                   => 'required|array|min:1',
            'lines.*.indicator_id'    => 'required|integer|exists:pcr_indicators,id',
            'lines.*.description'     => ['required', 'string', Html::maxChars(500)],
            'lines.*.target_date'     => 'nullable|date',
            'lines.*.allotted_budget' => 'nullable|numeric|min:0',
            'lines.*.user_ids'        => 'sometimes|array',
            'lines.*.user_ids.*'      => 'integer|exists:users,id',
        ]);

        try {
            $made = $replacements->proposeMany($request->user(), $data['lines']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data'  => 'proposed',
            'count' => count($made),
        ], 201);
    }

    public function decideForm(Request $request, $id, TargetReplacementService $replacements)
    {
        $form = PcrForm::findOrFail($id);
        $approve = $request->boolean('approve');
        $note = null;

        if (! $approve) {
            $data = $request->validate([
                'note' => 'required|string|max:500',
            ]);
            $note = trim($data['note']);
        }

        try {
            $replacements->decideForm($request->user(), (int) $form->id, $approve, $note);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $approve ? 'approved' : 'rejected']);
    }

    public function approve(Request $request, $id, TargetReplacementService $replacements)
    {
        $replacement = PcrTargetReplacement::findOrFail($id);

        try {
            $replacements->approve($request->user(), $replacement);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => 'approved']);
    }

    public function reject(Request $request, $id, TargetReplacementService $replacements)
    {
        $replacement = PcrTargetReplacement::findOrFail($id);

        try {
            $replacements->reject($request->user(), $replacement);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => 'rejected']);
    }
}
