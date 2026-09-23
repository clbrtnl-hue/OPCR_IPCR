<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\PcrComment;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\User;
use App\Support\Html;
use App\Services\PcrWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PcrCommentController extends Controller
{
    private const STAGE_FOR_ROLE = [
        'program_head' => 'head',
        'vp'           => 'vp',
        'qa'           => 'qa',
        'president'    => 'president',
        'employee'     => 'employee',
        'admin'        => 'qa',
    ];

    public function index(Request $request, $id)
    {
        $form = PcrForm::with('orgUnit')->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        return response()->json(
            PcrComment::with('mentions:id,name,role')
                ->where('form_id', $form->id)
                ->orderBy('created_at')
                ->get()
        );
    }

    public function mentionables(Request $request, $id)
    {
        $form = PcrForm::with('orgUnit')->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $ids = [
            $form->user_id,
            $form->orgUnit?->head_user_id,
            $form->orgUnit?->vp_user_id,
        ];

        $collegeWide = User::whereIn('role', ['qa', 'president', 'admin'])
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $unitMembers = User::where('org_unit_id', $form->org_unit_id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $all = array_unique(array_filter(array_merge($ids, $collegeWide, $unitMembers)));

        return response()->json(
            User::whereIn('id', $all)
                ->where('status', 'active')
                ->orderByPerson()
                ->get(['id', 'name', 'role', 'position_title'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'form_id'      => 'required|integer|exists:pcr_forms,id',
            'indicator_id' => 'nullable|integer|exists:pcr_indicators,id',
            'body'         => 'required|string|max:5000',
            'mentions'     => 'nullable|array',
            'mentions.*'   => 'integer|exists:users,id',
        ]);

        $user = $request->user();
        $form = PcrForm::with('orgUnit')->findOrFail($data['form_id']);

        if (! PcrWorkflow::canView($user, $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        if (! empty($data['indicator_id'])) {
            $belongs = PcrIndicator::where('id', $data['indicator_id'])
                ->whereHas('output', fn ($q) => $q->where('form_id', $form->id))
                ->exists();

            if (! $belongs) {
                return response()->json(['message' => 'That indicator is not part of this form.'], 422);
            }
        }

        $comment = DB::transaction(function () use ($data, $form, $user) {
            $comment = PcrComment::create([
                'form_id'      => $form->id,
                'indicator_id' => $data['indicator_id'] ?? null,
                'stage'        => self::STAGE_FOR_ROLE[$user->role] ?? 'employee',
                'body'         => Html::clean($data['body']),
                'author_id'    => $user->id,
                'author_name'  => $user->name,
                'author_role'  => $user->role,
            ]);

            $mentioned = array_unique($data['mentions'] ?? []);

            if ($mentioned) {
                $comment->mentions()->sync($mentioned);
            }

            return $comment;
        });

        $this->notify($comment, $form, $user, $data['mentions'] ?? []);

        return response()->json([
            'data'    => 'created',
            'comment' => $comment->load('mentions:id,name,role'),
        ], 201);
    }

    private function notify(PcrComment $comment, PcrForm $form, User $author, array $mentions): void
    {
        $subject = $form->owner?->name ?? $form->orgUnit?->name;
        $excerpt = mb_substr($comment->body, 0, 160);

        Notification::sendMany(
            $mentions,
            'mention',
            "{$author->name} mentioned you",
            $excerpt,
            $form->id
        );

        $watchers = array_diff(
            array_filter([
                $form->user_id,
                $form->orgUnit?->head_user_id,
                $form->orgUnit?->vp_user_id,
            ]),
            $mentions
        );

        Notification::sendMany(
            $watchers,
            'comment',
            "New remark on {$subject}'s " . strtoupper($form->type),
            $excerpt,
            $form->id
        );
    }
}
