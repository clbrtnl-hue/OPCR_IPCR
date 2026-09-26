<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PcrAccomplishment;
use App\Models\PcrAttachment;
use App\Models\PcrIndicator;
use App\Models\RatingPeriod;
use App\Support\Html;
use App\Services\IndicatorProgressService;
use App\Services\PcrWorkflow;
use Illuminate\Http\Request;

class PcrAccomplishmentController extends Controller
{
    public const MAX_UPLOAD_KB = 5120;

    public const ALLOWED_MIMES = 'jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx';

    public function store(Request $request)
    {
        $data = $request->validate([
            'indicator_id'          => 'required|integer|exists:pcr_indicators,id',
            'rating_period_id'      => 'required|integer|exists:rating_periods,id',
            'actual_accomplishment' => ['nullable', 'string', Html::maxChars(500)],
            'remarks'               => ['nullable', 'string', Html::maxChars(500)],
        ]);

        $guard = $this->guardWritable($request, $data['indicator_id'], $data['rating_period_id']);

        if ($guard) {
            return $guard;
        }

        $accomplishment = PcrAccomplishment::updateOrCreate(
            [
                'indicator_id'     => $data['indicator_id'],
                'rating_period_id' => $data['rating_period_id'],
            ],
            [
                'actual_accomplishment' => Html::clean($data['actual_accomplishment'] ?? null),
                'remarks' => Html::clean($data['remarks'] ?? null),
            ]
        );

        app(IndicatorProgressService::class)->syncFromRecord($accomplishment->indicator);

        return response()->json([
            'data'           => 'saved',
            'accomplishment' => $accomplishment->load('attachments'),
        ]);
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'indicator_id'     => 'required|integer|exists:pcr_indicators,id',
            'rating_period_id' => 'required|integer|exists:rating_periods,id',
            'file'             => 'required|file|max:' . self::MAX_UPLOAD_KB . '|mimes:' . self::ALLOWED_MIMES,
        ]);

        $guard = $this->guardWritable($request, $data['indicator_id'], $data['rating_period_id']);

        if ($guard) {
            return $guard;
        }

        $accomplishment = PcrAccomplishment::firstOrCreate([
            'indicator_id'     => $data['indicator_id'],
            'rating_period_id' => $data['rating_period_id'],
        ]);

        $file      = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $fileName  = 'pcr-' . uniqid() . '.' . $extension;
        $size      = $file->getSize();
        $mime      = $file->getMimeType();
        $original  = $file->getClientOriginalName();

        $file->move(public_path('uploads/pcr'), $fileName);

        $attachment = PcrAttachment::create([
            'accomplishment_id' => $accomplishment->id,
            'file_path'         => $fileName,
            'original_name'     => $original,
            'mime'              => $mime,
            'file_size'         => $size,
            'uploaded_by'       => $request->user()->id,
            'uploaded_by_name'  => $request->user()->name,
        ]);

        ActivityLog::record('PcrAttachment', $attachment->id, 'upload', "Uploaded evidence {$original}");

        app(IndicatorProgressService::class)->syncFromRecord(
            PcrIndicator::findOrFail($data['indicator_id'])
        );

        return response()->json(['data' => 'uploaded', 'attachment' => $attachment], 201);
    }

    public function destroyAttachment(Request $request, $id)
    {
        $attachment = PcrAttachment::with('accomplishment.indicator.output.form')->findOrFail($id);
        $user       = $request->user();
        $form       = $attachment->accomplishment->indicator->output->form;

        if (! $user->isAdmin() && ! PcrWorkflow::owns($user, $form)) {
            return response()->json(['message' => 'Only the owner of this form can remove its evidence.'], 403);
        }

        if (in_array($form->status, ['rated', 'final'], true)) {
            return response()->json(['message' => 'This form has been rated and can no longer be changed.'], 409);
        }

        if ($message = PcrWorkflow::lockMessage(
            $user,
            PcrWorkflow::periodForWrite($form, $attachment->accomplishment->rating_period_id)
        )) {
            return response()->json(['message' => $message], 409);
        }

        $path = public_path('uploads/pcr/' . $attachment->file_path);

        if (is_file($path)) {
            @unlink($path);
        }

        $name      = $attachment->original_name;
        $indicator = $attachment->accomplishment->indicator;
        $attachment->delete();

        app(IndicatorProgressService::class)->syncFromRecord($indicator);

        ActivityLog::record('PcrAttachment', (int) $id, 'delete', "Removed evidence {$name}");

        return response()->json(['data' => 'deleted']);
    }

    private function guardWritable(Request $request, int $indicatorId, int $periodId)
    {
        $indicator = PcrIndicator::with('output.form')->findOrFail($indicatorId);
        $form      = $indicator->output->form;
        $user      = $request->user();

        if (! $user->isAdmin() && ! PcrWorkflow::owns($user, $form)) {
            return response()->json([
                'message' => 'Only the owner of this form can record accomplishments on it.',
            ], 403);
        }

        if (in_array($form->status, ['rated', 'final'], true)) {
            return response()->json([
                'message' => 'This form has been rated and can no longer be changed.',
            ], 409);
        }

        $period = RatingPeriod::findOrFail($periodId);

        if ($period->isLockedFor($user)) {
            return response()->json([
                'message' => "{$period->label} is locked. It is view-only now — ask an administrator to unlock it.",
            ], 409);
        }

        if (! $user->isAdmin() && ! $period->isOpen()) {
            return response()->json([
                'message' => "{$period->label} is not open. Ask an administrator to open it.",
            ], 409);
        }

        if ((int) $period->school_year_id !== (int) $form->school_year_id) {
            return response()->json([
                'message' => 'That review period belongs to a different school year.',
            ], 422);
        }

        if ($indicator->rating_period_id && (int) $indicator->rating_period_id !== (int) $periodId) {
            return response()->json([
                'message' => 'This line belongs to a different review period.',
            ], 422);
        }

        return null;
    }
}
