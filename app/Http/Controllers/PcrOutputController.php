<?php

namespace App\Http\Controllers;

use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrTargetAssignment;
use App\Services\PcrWorkflow;
use App\Support\Html;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PcrOutputController extends Controller
{
    public function store(Request $request)
    {
        $id = $request->input('id');

        $data = $request->validate([
            'id'         => 'nullable|integer|exists:pcr_outputs,id',
            'form_id'    => 'required|integer|exists:pcr_forms,id',
            'section'          => ['required', Rule::in(PcrOutput::SECTIONS)],
            'title'            => ['required_without:parent_output_id', 'nullable', 'string', Html::maxChars(500)],
            'parent_output_id' => 'nullable|integer|exists:pcr_outputs,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $form = PcrForm::findOrFail($data['form_id']);

        if (! PcrWorkflow::canEditCommitments($request->user(), $form)) {
            return response()->json([
                'message' => 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        if ($message = PcrWorkflow::lockMessage($request->user(), PcrWorkflow::periodForWrite($form))) {
            return response()->json(['message' => $message], 409);
        }

        if ($form->type === 'ipcr' && $data['section'] === 'strategic' && ! $id) {
            return response()->json([
                'message' => 'Strategic priorities belong to the office OPCR, not an individual IPCR.',
            ], 422);
        }

        $existing = $id ? PcrOutput::find($id) : null;

        $parentId = array_key_exists('parent_output_id', $data)
            ? $data['parent_output_id']
            : $existing?->parent_output_id;

        $parent = null;
        $sameForm = false;

        if (! empty($parentId)) {
            $parent    = PcrOutput::with('form')->find($parentId);
            $sameForm  = $parent && (int) $parent->form_id === (int) $form->id;

            if (! $parent) {
                return response()->json([
                    'message' => 'That MFO/PPA no longer exists.',
                ], 422);
            }

            if ($sameForm) {
                if ((int) $parent->form_id !== (int) $form->id || $parent->section !== $data['section']) {
                    return response()->json([
                        'message' => 'A nested PPA has to sit under an MFO in the same section.',
                    ], 422);
                }
            } else {
                if (
                    $parent->form?->type !== 'opcr'
                    || (int) $parent->form->school_year_id !== (int) $form->school_year_id
                ) {
                    return response()->json([
                        'message' => 'Pick an MFO/PPA from the college OPCR for this school year.',
                    ], 422);
                }

                if (! PcrWorkflow::opcrIsVisible($parent->form)) {
                    return response()->json([
                        'message' => 'The college OPCR has not been published yet.',
                    ], 422);
                }
            }
        }

        $title = filled($data['title'] ?? null)
            ? $data['title']
            : ($existing?->title ?? ($sameForm ? 'New PPA' : ($parent?->title ?? 'Untitled')));

        $attributes = [
            'form_id'          => $form->id,
            'parent_output_id' => $parent?->id,
            'section'          => $sameForm ? $parent->section : ($parent?->section ?? $data['section']),
            'title'            => $title,
            // Same rule as indicators: editing a heading keeps its position.
            'sort_order'       => $data['sort_order']
                ?? ($id
                    ? PcrOutput::where('id', $id)->value('sort_order')
                    : PcrOutput::where('form_id', $form->id)->max('sort_order') + 1),
        ];

        if ($id) {
            $output = PcrOutput::findOrFail($id);
            $output->update($attributes);

            return response()->json(['data' => 'updated', 'output' => $output->load('indicators')]);
        }

        $output = PcrOutput::create($attributes);

        return response()->json(['data' => 'created', 'output' => $output->load('indicators')], 201);
    }

    public function destroy(Request $request, $id)
    {
        $output = PcrOutput::with('form')->findOrFail($id);

        if (! PcrWorkflow::canEditCommitments($request->user(), $output->form)) {
            return response()->json([
                'message' => 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        if ($message = PcrWorkflow::lockMessage($request->user(), PcrWorkflow::periodForWrite($output->form))) {
            return response()->json(['message' => $message], 409);
        }

        // A heading handed down from the OPCR still belongs on this IPCR only
        // while the owner wants it. Deleting it takes their copy off the form.
        if ($output->parent_output_id && $output->form->user_id) {
            $parentLineIds = PcrIndicator::where('output_id', $output->parent_output_id)->pluck('id');

            PcrTargetAssignment::whereIn('indicator_id', $parentLineIds)
                ->where('user_id', $output->form->user_id)
                ->delete();
        }

        $nested = PcrOutput::where('parent_output_id', $output->id)
            ->where('form_id', $output->form_id)
            ->exists();

        if ($nested) {
            return response()->json([
                'message' => 'Remove the PPAs nested under this MFO first.',
            ], 409);
        }

        $output->delete();

        return response()->json(['data' => 'deleted']);
    }
}
