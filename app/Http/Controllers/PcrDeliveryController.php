<?php

namespace App\Http\Controllers;

use App\Models\PcrComment;
use App\Models\PcrIndicator;
use App\Services\PcrWorkflow;
use Illuminate\Http\Request;

class PcrDeliveryController extends Controller
{
    public function rollup(Request $request, $id)
    {
        $data = $request->validate([
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $indicator = PcrIndicator::with('output.form.orgUnit')->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $indicator->output->form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $period = $data['rating_period_id'] ?? null;

        $children = $indicator->children()
            ->with([
                'output.form.owner:id,name,position_title,image',
                'output.form.ratingPeriod:id,label,seq',
                'accomplishments.attachments',
            ])
            ->withCount('children')
            ->get()
            ->sortBy(fn ($child) => $child->output?->form?->owner?->name ?? '')
            ->values();

        $comments = PcrComment::with('mentions:id,name,role')
            ->whereIn('indicator_id', $children->pluck('id'))
            ->orderBy('created_at')
            ->get()
            ->groupBy('indicator_id');

        $delivered = $children->map(function ($child) use ($comments, $period) {
            $form           = $child->output?->form;
            $accomplishment = $child->accomplishments->first();

            return [
                'child_id'            => $child->id,
                'assigned_by'         => $child->assigned_by,
                'form_id'             => $form?->id,
                'form_status'         => $form?->status,
                'rating_period_id'    => $child->rating_period_id,
                'rating_period_label' => $form?->ratingPeriod?->label,
                'is_current_period'   => $period === null
                    || (int) $child->rating_period_id === (int) $period,
                'owner'               => $form?->owner ? [
                    'id'             => $form->owner->id,
                    'name'           => $form->owner->name,
                    'position_title' => $form->owner->position_title,
                    'image'          => $form->owner->image,
                ] : null,
                'description'     => $child->description,
                'progress_status' => $child->progress_status,
                'progress_pct'    => $child->progress_pct,
                'target_date'     => $child->target_date,
                'completed_on'    => $child->completed_on,
                'children_count'  => $child->children_count,
                'accomplishment'  => $accomplishment ? [
                    'id'                    => $accomplishment->id,
                    'actual_accomplishment' => $accomplishment->actual_accomplishment,
                    'remarks'               => $accomplishment->remarks,
                    'updated_at'            => $accomplishment->updated_at,
                    'attachments'           => $accomplishment->attachments->map(fn ($file) => [
                        'id'               => $file->id,
                        'file_path'        => $file->file_path,
                        'original_name'    => $file->original_name,
                        'mime'             => $file->mime,
                        'file_size'        => $file->file_size,
                        'uploaded_by_name' => $file->uploaded_by_name,
                        'created_at'       => $file->created_at,
                    ])->values(),
                ] : null,
                'comments' => ($comments->get($child->id) ?? collect())
                    ->map(fn ($comment) => [
                        'id'          => $comment->id,
                        'author_name' => $comment->author_name,
                        'author_role' => $comment->author_role,
                        'stage'       => $comment->stage,
                        'body'        => $comment->body,
                        'created_at'  => $comment->created_at,
                        'mentions'    => $comment->mentions,
                    ])->values(),
            ];
        });

        $named = $delivered->pluck('owner.id')->filter();

        $pending = $indicator->assignments()
            ->with('user:id,name,position_title,image')
            ->get()
            ->reject(fn ($row) => $named->contains($row->user_id))
            ->map(fn ($row) => [
                'child_id'            => null,
                'assignment_id'       => $row->id,
                'assigned_by'         => $row->assigned_by,
                'form_id'             => null,
                'form_status'         => null,
                'rating_period_id'    => $row->rating_period_id,
                'rating_period_label' => null,
                'is_current_period'   => $period === null
                    || (int) $row->rating_period_id === (int) $period,
                'owner'               => $row->user ? [
                    'id'             => $row->user->id,
                    'name'           => $row->user->name,
                    'position_title' => $row->user->position_title,
                    'image'          => $row->user->image,
                ] : null,
                'description'     => null,
                'progress_status' => 'not_started',
                'progress_pct'    => 0,
                'target_date'     => null,
                'completed_on'    => null,
                'children_count'  => 0,
                'accomplishment'  => null,
                'comments'        => [],
            ]);

        return response()->json([
            'indicator' => [
                'id'              => $indicator->id,
                'description'     => $indicator->description,
                'target_date'     => $indicator->target_date,
                'progress_status' => $indicator->progress_status,
                'progress_pct'    => $indicator->progress_pct,
                'delay'           => $indicator->delay,
            ],
            'delivered' => $delivered->concat($pending)->values(),
        ]);
    }
}