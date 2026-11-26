<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCategory;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrTargetAssignment;
use App\Models\User;
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
            // A new heading may be blank. The sheet shows a placeholder until
            // it is named, and readiness still blocks an unnamed row.
            'title'            => ['nullable', 'string', Html::maxChars(500)],
            'attendance_category_id' => 'nullable|integer',
            'parent_output_id' => 'nullable|integer|exists:pcr_outputs,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $form = PcrForm::findOrFail($data['form_id']);
        $existing = $id ? PcrOutput::find($id) : null;

        if ($existing && $this->categoryOnly($data, $existing)) {
            return $this->linkAttendance($request->user(), $form, $existing, $data['attendance_category_id'] ?? null);
        }

        if (! PcrWorkflow::canEditCommitments($request->user(), $form)) {
            return response()->json([
                'message' => PcrWorkflow::commitmentClosedMessage($form)
                    ?? 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        if ($message = PcrWorkflow::formLockMessage($request->user(), $form)) {
            return response()->json(['message' => $message], 409);
        }

        if ($form->type === 'ipcr' && $data['section'] === 'strategic' && ! $id) {
            return response()->json([
                'message' => 'Strategic priorities belong to the office OPCR, not an individual IPCR.',
            ], 422);
        }

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

        // Sent text replaces the heading, including a blank that clears it.
        // A blank new row stays blank so the sheet can show a placeholder.
        // One that answers an office MFO and was given no name still takes
        // that MFO's name.
        if (array_key_exists('title', $data)) {
            $title = trim((string) ($data['title'] ?? ''));
        } elseif ($existing) {
            $title = $existing->title;
        } else {
            $title = '';
        }

        if ($title === '' && ! $existing && $parent && ! $sameForm) {
            $title = $parent->title;
        }

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

        $section = $attributes['section'];

        if ($section !== 'support') {
            $attributes['attendance_category_id'] = null;
        }

        if ($id) {
            $output = PcrOutput::findOrFail($id);
            $output->update($attributes);

            return response()->json(['data' => 'updated', 'output' => $output->load('indicators')]);
        }

        $output = PcrOutput::create($attributes);

        return response()->json(['data' => 'created', 'output' => $output->load('indicators')], 201);
    }

    /**
     * A category pick sends no title. Commitment edits keep the category already stored.
     *
     * @param  array<string, mixed>  $data
     */
    private function categoryOnly(array $data, ?PcrOutput $existing): bool
    {
        return $existing
            && array_key_exists('attendance_category_id', $data)
            && ! array_key_exists('title', $data)
            && ! array_key_exists('parent_output_id', $data)
            && ! array_key_exists('sort_order', $data);
    }

    private function linkAttendance(User $user, PcrForm $form, PcrOutput $output, mixed $categoryId)
    {
        if (! PcrWorkflow::canLinkAttendance($user, $form)) {
            return response()->json([
                'message' => 'Only the head can choose attendance evidence while rating this IPCR.',
            ], 403);
        }

        if ((int) $output->form_id !== (int) $form->id || $output->section !== 'support') {
            return response()->json([
                'message' => 'Attendance evidence belongs on a support line.',
            ], 422);
        }

        if ($categoryId && ! AttendanceCategory::query()->whereKey($categoryId)->exists()) {
            return response()->json([
                'message' => 'Choose an attendance category from this organization.',
            ], 422);
        }

        $output->update(['attendance_category_id' => $categoryId ?: null]);

        return response()->json(['data' => 'updated', 'output' => $output->load('indicators')]);
    }

    public function destroy(Request $request, $id)
    {
        $output = PcrOutput::with('form')->findOrFail($id);

        if (! PcrWorkflow::canEditCommitments($request->user(), $output->form)) {
            return response()->json([
                'message' => PcrWorkflow::commitmentClosedMessage($output->form)
                    ?? 'Commitments can only be changed while the form is a draft or has been returned to you.',
            ], 409);
        }

        if ($message = PcrWorkflow::formLockMessage($request->user(), $output->form)) {
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
