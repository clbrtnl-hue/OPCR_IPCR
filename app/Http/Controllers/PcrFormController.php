<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\PcrAttachment;
use App\Models\PcrComment;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrRating;
use App\Models\RatingPeriod;
use App\Models\PcrStatusLog;
use App\Models\PcrTargetAssignment;
use App\Models\User;
use App\Services\PcrWorkflow;
use App\Services\WorkflowSettings;
use App\Support\Html;
use App\Support\PcrOutline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PcrFormController extends Controller
{
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = PcrForm::with([
            'schoolYear:id,label',
            'orgUnit:id,name,code',
            'owner:id,name,position_title,image',
            'ratingPeriod:id,label,seq,is_active',
        ])
            ->withCount('outputs')
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($year = $request->query('school_year_id')) {
            $query->where('school_year_id', $year);
        }

        if ($period = $request->query('rating_period_id')) {
            $query->where('rating_period_id', $period);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('mine')) {
            $this->scopeToOwner($query, $user);
        } else {
            $this->scopeToViewer($query, $user, $request->boolean('queue'));
        }

        return response()->json($query->get());
    }

    public function show(Request $request, $id)
    {
        $form = PcrForm::with([
            'schoolYear.periods',
            'ratingPeriod:id,label,seq,is_active',
            'orgUnit.head:id,name,position_title',
            'orgUnit.vp:id,name,position_title',
            'owner:id,name,position_title,image',
            'headReviewer:id,name,position_title',
            'vpReviewer:id,name,position_title',
            'outputs.indicators.accomplishments.attachments',
            'outputs.indicators.ratings',
            'summaries',
            'statusLogs',
        ])->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        // An IPCR carries the office target each line rolls up to; an OPCR
        // carries the individual lines committed against each target. Loaded
        // with `missing` so the accomplishments and ratings fetched above are
        // kept — a plain load() here refetches the indicators and drops them.
        if ($form->type === 'ipcr') {
            $form->loadMissing('outputs.indicators.parent:id,output_id,description');
        }

        $form->loadMissing([
            'outputs.indicators.children' => fn ($query) => $query
                ->select([
                    'id', 'parent_indicator_id', 'output_id', 'description', 'assigned_by',
                    'progress_status', 'progress_pct', 'rating_period_id',
                    'target_date', 'completed_on',
                ])
                ->withCount([
                    'accomplishments as recorded_count' => fn ($q) => $q
                        ->whereNotNull('actual_accomplishment')
                        ->where('actual_accomplishment', '!=', ''),
                    'attachments as attachments_count',
                ]),
            'outputs.indicators.children.output.form.owner:id,name,position_title,image,role',
            'outputs.indicators.assignments.user:id,name,position_title,image',
        ]);

        $this->hidePresidentZeroOnHisOpcr($form);

        $form->outputs->loadCount([
            'childOutputs as nested_outputs_count' => fn ($q) => $q->where('form_id', $form->id),
            'childOutputs as delegated_outputs_count' => fn ($q) => $q->where('form_id', '!=', $form->id),
        ]);

        $form->setRelation('outputs', PcrOutline::numbered($form->outputs));

        $payload = $form->toArray();

        if ($form->type === 'ipcr' && $form->user_id) {
            $payload['assigned_targets'] = $this->assignedTargetsFor($form);
        }

        if ($form->type === 'opcr') {
            $payload['rating_window_open'] = PcrWorkflow::opcrRatingMonthOpen($form);
        }

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $id   = $request->input('id');

        $data = $request->validate([
            'id'             => 'nullable|integer|exists:pcr_forms,id',
            'type'           => ['required', Rule::in(['opcr', 'ipcr'])],
            'school_year_id' => 'required|integer|exists:school_years,id',
            'org_unit_id'    => 'required|integer|exists:org_units,id',
            'user_id'        => 'nullable|integer|exists:users,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
            'header_note'    => 'nullable|string',
        ]);

        if ($id) {
            $form = PcrForm::findOrFail($id);

            if (! PcrWorkflow::canEditCommitments($user, $form)) {
                return response()->json([
                    'message' => 'This form can only be edited while it is a draft or has been returned to you.',
                ], 409);
            }

            if ($message = PcrWorkflow::lockMessage($user, PcrWorkflow::periodForWrite($form))) {
                return response()->json(['message' => $message], 409);
            }

            $form->update(['header_note' => $data['header_note'] ?? null]);

            return response()->json(['data' => 'updated', 'form' => $form]);
        }

        $ownerId = $data['type'] === 'ipcr' ? ($data['user_id'] ?? $user->id) : null;

        // An IPCR is filed per rating period; an OPCR covers the whole year.
        $periodId = null;

        if ($data['type'] === 'ipcr') {
            if (! empty($data['rating_period_id'])) {
                $period = RatingPeriod::find($data['rating_period_id']);

                if ((int) $period->school_year_id !== (int) $data['school_year_id']) {
                    return response()->json([
                        'message' => 'That rating period belongs to a different school year.',
                    ], 422);
                }

                $periodId = $period->id;
            } else {
                $periodId = RatingPeriod::where('school_year_id', $data['school_year_id'])
                    ->orderByDesc('is_active')
                    ->orderBy('seq')
                    ->value('id');
            }
        }

        if ($message = PcrWorkflow::lockMessage($user, $periodId)) {
            return response()->json(['message' => $message], 409);
        }

        if ($data['type'] === 'ipcr' && ! $user->isAdmin() && (int) $ownerId !== (int) $user->id) {
            return response()->json(['message' => 'You can only create your own IPCR.'], 403);
        }

        if ($data['type'] === 'opcr') {
            $rules = app(WorkflowSettings::class);

            if (! $user->isAdmin() && ! in_array($user->role, $rules->opcr('creator_roles'), true)) {
                return response()->json([
                    'message' => 'Only the president opens the college OPCR. It goes to QA for approval, then the president publishes it.',
                ], 403);
            }

            $existing = PcrForm::where('type', 'opcr')
                ->where('school_year_id', $data['school_year_id'])
                ->when(
                    ! $rules->opcrOnePerOrganization(),
                    fn ($q) => $q->where('org_unit_id', $data['org_unit_id'])
                )
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => $rules->opcrOnePerOrganization()
                        ? 'The college already has an OPCR for this year.'
                        : 'That office already has an OPCR for this year.',
                    'form_id' => $existing->id,
                ], 409);
            }
        }

        $existing = PcrForm::where('type', $data['type'])
            ->where('school_year_id', $data['school_year_id'])
            ->where('org_unit_id', $data['org_unit_id'])
            ->where('user_id', $ownerId)
            ->where('rating_period_id', $periodId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A form already exists for this school year and unit.',
                'form_id' => $existing->id,
            ], 409);
        }

        $form = PcrForm::create([
            'type'             => $data['type'],
            'school_year_id'   => $data['school_year_id'],
            'org_unit_id'      => $data['org_unit_id'],
            'user_id'          => $ownerId,
            'rating_period_id' => $periodId,
            'header_note'      => $data['header_note'] ?? null,
            'status'           => 'draft',
        ]);

        PcrStatusLog::record($form->id, null, 'draft', 'Form created');
        ActivityLog::record('PcrForm', $form->id, 'create', strtoupper($form->type) . ' created');

        return response()->json(['data' => 'created', 'form' => $form->load('orgUnit', 'owner')], 201);
    }

    public function bulkStore(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'required|integer|exists:school_years,id',
            'org_unit_id'      => 'required|integer|exists:org_units,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
            'user_ids'         => 'nullable|array',
            'user_ids.*'       => 'integer|exists:users,id',
        ]);

        $user = $request->user();
        $unit = OrgUnit::findOrFail($data['org_unit_id']);

        if (! $user->isAdmin()
            && (int) $unit->head_user_id !== (int) $user->id
            && (int) $unit->vp_user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Only an administrator, or the head or VP of that office, can open its IPCRs.',
            ], 403);
        }

        if (! empty($data['rating_period_id'])) {
            $period = RatingPeriod::find($data['rating_period_id']);

            if ((int) $period->school_year_id !== (int) $data['school_year_id']) {
                return response()->json([
                    'message' => 'That rating period belongs to a different school year.',
                ], 422);
            }

            $periodId = $period->id;
        } else {
            $periodId = RatingPeriod::where('school_year_id', $data['school_year_id'])
                ->orderByDesc('is_active')
                ->orderBy('seq')
                ->value('id');
        }

        if (! $periodId) {
            return response()->json([
                'message' => 'That school year has no review period to file an IPCR against.',
            ], 422);
        }

        if ($message = PcrWorkflow::lockMessage($user, $periodId)) {
            return response()->json(['message' => $message], 409);
        }

        $members = User::where('org_unit_id', $unit->id)
            ->where('status', 'active')
            ->where('role', '!=', 'admin')
            ->when(! empty($data['user_ids']), fn ($q) => $q->whereIn('id', $data['user_ids']))
            ->orderByPerson()
            ->get();

        if ($members->isEmpty()) {
            return response()->json([
                'message' => 'Nobody in that office is waiting for an IPCR.',
            ], 422);
        }

        $taken = PcrForm::where('type', 'ipcr')
            ->where('school_year_id', $data['school_year_id'])
            ->where('rating_period_id', $periodId)
            ->whereIn('user_id', $members->pluck('id'))
            ->pluck('user_id')
            ->all();

        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($members, $taken, $data, $periodId, $unit, &$created, &$skipped) {
            foreach ($members as $member) {
                if (in_array($member->id, $taken, true)) {
                    $skipped++;

                    continue;
                }

                $form = PcrForm::create([
                    'type'             => 'ipcr',
                    'school_year_id'   => $data['school_year_id'],
                    'org_unit_id'      => $member->org_unit_id ?? $unit->id,
                    'user_id'          => $member->id,
                    'rating_period_id' => $periodId,
                    'status'           => 'draft',
                ]);

                PcrStatusLog::record($form->id, null, 'draft', 'Opened for the cycle');
                $created[] = $form;
            }
        });

        foreach ($created as $form) {
            Notification::send(
                $form->user_id,
                'form',
                'Your IPCR for this period is open',
                'It is waiting as a draft — list your MFO/PPAs and success indicators, then submit it.',
                $form->id
            );
        }

        ActivityLog::record(
            'OrgUnit',
            $unit->id,
            'create',
            count($created) . ' IPCR(s) opened for ' . $unit->name
        );

        return response()->json([
            'data'    => 'created',
            'created' => count($created),
            'skipped' => $skipped,
            'forms'   => collect($created)->map(fn ($f) => $f->load('owner:id,name'))->values(),
        ], 201);
    }

    public function templates()
    {
        return response()->json(array_values(config('pms_templates', [])));
    }

    public function applyTemplate(Request $request, $id)
    {
        $data = $request->validate(['template' => 'required|string']);

        $form     = PcrForm::with('outputs')->findOrFail($id);
        $user     = $request->user();
        $template = collect(config('pms_templates', []))->firstWhere('key', $data['template']);

        if (! $template) {
            return response()->json(['message' => 'That template no longer exists.'], 422);
        }

        if (! PcrWorkflow::owns($user, $form) || ! PcrWorkflow::canEditCommitments($user, $form)) {
            return response()->json([
                'message' => 'Only the owner can build this form, and only while it is a draft or has been returned.',
            ], 409);
        }

        $periodId = $form->type === 'opcr' ? null : $form->rating_period_id;

        if ($form->type === 'ipcr' && ! $periodId) {
            $periodId = RatingPeriod::where('school_year_id', $form->school_year_id)
                ->orderByDesc('is_active')
                ->orderBy('seq')
                ->value('id');
        }

        $outputs = 0;
        $lines   = 0;

        DB::transaction(function () use ($template, $form, $periodId, &$outputs, &$lines) {
            $nextOutput = (PcrOutput::where('form_id', $form->id)->max('sort_order') ?? 0) + 1;

            foreach ($template['outputs'] as $block) {
                $output = PcrOutput::where('form_id', $form->id)
                    ->where('section', $template['section'])
                    ->where('title', $block['title'])
                    ->whereNull('parent_output_id')
                    ->first();

                if (! $output) {
                    $output = PcrOutput::create([
                        'form_id'    => $form->id,
                        'section'    => $template['section'],
                        'title'      => $block['title'],
                        'sort_order' => $nextOutput++,
                    ]);

                    $outputs++;
                }

                $existing = PcrIndicator::where('output_id', $output->id)
                    ->pluck('description')
                    ->map(fn ($text) => Html::toText($text))
                    ->all();

                $nextLine = (PcrIndicator::where('output_id', $output->id)->max('sort_order') ?? 0) + 1;

                foreach ($block['indicators'] as $description) {
                    if (in_array($description, $existing, true)) {
                        continue;
                    }

                    PcrIndicator::create([
                        'output_id'        => $output->id,
                        'rating_period_id' => $periodId,
                        'description'      => Html::clean($description),
                        'sort_order'       => $nextLine++,
                    ]);

                    $lines++;
                }
            }
        });

        ActivityLog::record('PcrForm', $form->id, 'update', "Inserted the “{$template['name']}” template");

        return response()->json([
            'data'    => 'applied',
            'outputs' => $outputs,
            'lines'   => $lines,
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PcrForm::STATUSES)],
            'note'   => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $form = PcrForm::with('orgUnit')->findOrFail($id);
        $from = $form->status;
        $to   = $data['status'];

        if ($from === $to) {
            return response()->json(['message' => "This form is already {$to}."], 409);
        }

        if (! PcrWorkflow::canTransition($form->type, $from, $to)) {
            return response()->json([
                'message' => "A form that is {$from} cannot move to {$to}.",
            ], 409);
        }

        if (! PcrWorkflow::canView($user, $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $isOwnerSubmission = false;

        // On submission the destination is computed from the hierarchy, never
        // chosen by the sender — that is what stops anyone skipping a reviewer,
        // and what routes a ratee who has no head (or is the head) correctly.
        if (in_array($from, ['draft', 'returned'], true)
            && in_array($to, ['head_review', 'vp_review', 'qa_rating'], true)
            && PcrWorkflow::owns($user, $form)
        ) {
            $form->forceFill(PcrWorkflow::resolveReviewers($form))->save();
            $to = PcrWorkflow::nextStatusFor($form, $from) ?? $to;
            $isOwnerSubmission = true;
        }

        $isReviewStage = $form->type === 'ipcr' && in_array($from, ['head_review', 'vp_review'], true);

        if ($isOwnerSubmission) {
            // Submitting your own form is authorised by owning it; which stage
            // it lands on was decided above, not by the sender.
        } elseif ($isReviewStage) {
            // Authority comes from being named on this form, not from a role
            // label — which is what lets one person hold both posts.
            if (! PcrWorkflow::isReviewerFor($user, $form, $from)) {
                return response()->json([
                    'message' => 'This form is waiting for somebody else to review it.',
                ], 403);
            }
        } elseif (! PcrWorkflow::actorMayMoveTo($user, $form->type, $to)) {
            return response()->json([
                'message' => "Your role cannot move a form to {$to}.",
            ], 403);
        }

        if ($form->type === 'opcr' && $from === 'published' && $to === 'qa_rating'
            && ! PcrWorkflow::opcrRatingMonthOpen($form)
        ) {
            return response()->json([
                'message' => 'The college OPCR is sent for rating in December, with the last period.',
            ], 409);
        }

        $isSubmission = in_array($from, ['draft', 'returned'], true)
            && in_array($to, ['head_review', 'vp_review', 'qa_rating'], true);

        if ($isSubmission) {
            if (! PcrWorkflow::owns($user, $form)) {
                return response()->json(['message' => 'Only the owner of this form can submit it.'], 403);
            }

            if ($form->indicators()->count() === 0) {
                return response()->json([
                    'message' => 'Add at least one success indicator before submitting.',
                ], 422);
            }

            // A reviewer should not be the one to discover a blank narrative or a
            // missing file. The writer finishes both before the form leaves them.
            if ($form->type === 'ipcr' && $message = $this->unfinishedWriteUp($form)) {
                return response()->json(['message' => $message], 422);
            }

            $form->loadMissing('owner');

            // QA rates their own IPCR. It is not sent to the president.
            if ($form->owner?->role === 'qa') {
                $to = 'qa_rating';
                $form->forceFill([
                    'head_reviewer_id' => null,
                    'vp_reviewer_id'   => null,
                ])->save();
            }
        }

        if ($from === 'head_review' && $to !== 'returned') {
            $form->loadMissing('headReviewer');

            if ($form->headReviewer?->role === 'qa') {
                return response()->json([
                    'message' => 'Rate this IPCR and finalize it. It is not sent on to a VP or the president.',
                ], 409);
            }

            $to = PcrWorkflow::nextStatusFor($form, $from) ?? $to;
        }

        if (in_array($from, ['head_review', 'vp_review'], true) && $to !== 'returned' && $this->hasUnratedLines($form)) {
            return response()->json([
                'message' => 'Rate every line before sending this on.',
            ], 422);
        }

        $data['note'] = Html::clean($data['note'] ?? null);

        if ($to === 'returned' && Html::isBlank($data['note'])) {
            return response()->json([
                'message' => 'Say what needs fixing before returning the form.',
            ], 422);
        }

        DB::transaction(function () use ($form, $from, $to, $data, $user, $isSubmission) {
            $attributes = ['status' => $to];

            if ($isSubmission) {
                $attributes['submitted_at'] = now();
            }

            if ($to === 'vp_review') {
                $attributes['reviewed_by']      = $user->id;
                $attributes['reviewed_by_name'] = $user->name;
                $attributes['reviewed_at']      = now();
            }

            if ($to === 'qa_rating') {
                $attributes['vp_reviewed_by']      = $user->id;
                $attributes['vp_reviewed_by_name'] = $user->name;
                $attributes['vp_reviewed_at']      = now();
            }

            $form->update($attributes);
            PcrStatusLog::record($form->id, $from, $to, Html::toText($data['note'] ?? null) ?: null);

            // Say so when a stage was passed over, so the trail stays honest
            // about who actually saw the form.
            foreach (self::skippedBetween($form, $from, $to) as $skipped) {
                PcrStatusLog::record($form->id, $from, $to, $skipped);
            }
        });

        ActivityLog::record('PcrForm', $form->id, 'status', "Form moved from {$from} to {$to}");
        $this->notifyTransition($form, $from, $to, $data['note'] ?? null);

        return response()->json(['data' => 'updated', 'form' => $form->fresh()]);
    }

    private function notifyTransition(PcrForm $form, string $from, string $to, ?string $note): void
    {
        $note = Html::toText($note) ?: null;

        $label   = strtoupper($form->type);
        $subject = $form->owner?->name ?? $form->orgUnit?->name;

        $this->notifyOwnerTheirRatingMovedOn($form, $from, $to);

        if ($to === 'head_review') {
            Notification::send(
                $form->orgUnit?->head_user_id,
                'review',
                "{$label} from {$subject} needs your review",
                'It is waiting in your review queue.',
                $form->id
            );

            return;
        }

        if ($to === 'vp_review') {
            Notification::send(
                $form->orgUnit?->vp_user_id,
                'review',
                "{$label} from {$subject} needs your review",
                'The head has endorsed it.',
                $form->id
            );

            return;
        }

        $rules = app(WorkflowSettings::class);

        if ($form->type === 'opcr' && $to === 'qa_approval') {
            Notification::sendMany(
                $this->usersWithRoles($rules->opcr('approver_roles')),
                'approval',
                "OPCR for {$subject} needs your approval",
                'The targets are waiting for approval in your review queue.',
                $form->id
            );

            return;
        }

        if ($form->type === 'opcr' && $to === 'approved') {
            Notification::sendMany(
                $this->usersWithRoles($rules->opcr('publisher_roles')),
                'approved',
                "OPCR for {$subject} was approved",
                'It is ready to publish.',
                $form->id
            );

            return;
        }

        if ($form->type === 'opcr' && $to === 'published') {
            $this->notifyOpcrPublished($form);

            return;
        }

        if ($to === 'qa_rating') {
            Notification::sendMany(
                $this->usersWithRoles(['qa']),
                'rating',
                "{$label} from {$subject} is ready for rating",
                'Both reviewers have endorsed it.',
                $form->id
            );

            return;
        }

        if ($to === 'returned') {
            Notification::sendMany(
                $form->type === 'opcr' && ! $form->user_id
                    ? $this->usersWithRoles($rules->opcr('creator_roles'))
                    : [$form->user_id ?? $form->orgUnit?->head_user_id],
                'returned',
                "Your {$label} was returned for correction",
                $note,
                $form->id
            );
        }
    }

    /**
     * The person whose IPCR was just scored hears that it moved on.
     * A submission of their own form is not a rating, so it stays quiet.
     */
    private function notifyOwnerTheirRatingMovedOn(PcrForm $form, string $from, string $to): void
    {
        if ($form->type !== 'ipcr' || ! $form->user_id) {
            return;
        }

        $sentTo = match (true) {
            $from === 'head_review' && $to === 'vp_review' => 'the VP',
            in_array($from, ['head_review', 'vp_review'], true) && $to === 'qa_rating' => 'QA',
            default => null,
        };

        if (! $sentTo) {
            return;
        }

        Notification::send(
            $form->user_id,
            'review',
            "Your IPCR was rated and forwarded to {$sentTo}",
            $from === 'vp_review'
                ? 'The VP has rated it and sent it on.'
                : 'The head has rated it and sent it on.',
            $form->id
        );
    }

    private function notifyOpcrPublished(PcrForm $form): void
    {
        $form->loadMissing('schoolYear');

        $assigneeIds = PcrTargetAssignment::query()
            ->whereHas('indicator.output', fn ($q) => $q->where('form_id', $form->id))
            ->pluck('user_id');

        $legacyIds = PcrIndicator::query()
            ->whereHas('parent.output', fn ($q) => $q->where('form_id', $form->id))
            ->whereHas('output.form', fn ($q) => $q->where('type', 'ipcr')->whereNotNull('user_id'))
            ->with('output.form:id,user_id')
            ->get()
            ->pluck('output.form.user_id');

        $people = User::query()
            ->whereIn('id', $assigneeIds->merge($legacyIds)->filter()->unique())
            ->where('status', 'active')
            ->where('role', '!=', 'president')
            ->get(['id']);

        $year = $form->schoolYear?->label ?? 'this year';

        Notification::sendMany(
            $this->usersWithRoles(['qa']),
            'published',
            'The college OPCR is published',
            "The {$year} OPCR is out. Targets are visible and accountable staff can write their IPCR commitments.",
            $form->id
        );

        foreach ($people as $person) {
            $ipcr = PcrForm::query()
                ->where('type', 'ipcr')
                ->where('user_id', $person->id)
                ->where('school_year_id', $form->school_year_id)
                ->orderByDesc('rating_period_id')
                ->first();

            Notification::send(
                $person->id,
                'published',
                "The college OPCR is published",
                "The {$year} OPCR is out. You can now write your IPCR commitments against the targets assigned to you.",
                $ipcr?->id
            );
        }
    }

    private function usersWithRoles(array $roles): array
    {
        return User::whereIn('role', $roles)->where('status', 'active')->pluck('id')->all();
    }

    public function destroy(Request $request, $id)
    {
        $form = PcrForm::findOrFail($id);
        $user = $request->user();

        if (! $user->isAdmin() && ! ($form->status === 'draft' && PcrWorkflow::owns($user, $form))) {
            return response()->json([
                'message' => 'Only a draft you own can be deleted.',
            ], 409);
        }

        $type = strtoupper($form->type);
        $form->delete();

        ActivityLog::record('PcrForm', (int) $id, 'delete', "{$type} deleted");

        return response()->json(['data' => 'deleted']);
    }

    public function history(Request $request, $id)
    {
        $form = PcrForm::with(['owner:id,name,image', 'orgUnit:id,name'])->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $keeper       = $form->owner?->name ?? $form->orgUnit?->name;
        $outputIds    = PcrOutput::where('form_id', $form->id)->pluck('id');
        $indicatorIds = PcrIndicator::whereIn('output_id', $outputIds)->pluck('id');
        $events       = [];

        foreach (PcrStatusLog::where('form_id', $form->id)->get() as $log) {
            $events[] = [
                'kind'   => 'movement',
                'at'     => $log->created_at,
                'actor'  => $log->performed_by_name,
                'from'   => $log->from_status,
                'to'     => $log->to_status,
                'detail' => $log->note,
            ];
        }

        foreach (PcrComment::where('form_id', $form->id)->get() as $comment) {
            $events[] = [
                'kind'   => 'remark',
                'at'     => $comment->created_at,
                'actor'  => $comment->author_name,
                'role'   => $comment->author_role,
                'stage'  => $comment->stage,
                'detail' => Html::toText($comment->body),
                'line'   => $comment->indicator_id
                    ? Html::toText(PcrIndicator::where('id', $comment->indicator_id)->value('description'))
                    : null,
            ];
        }

        $attachments = PcrAttachment::whereHas(
            'accomplishment',
            fn ($q) => $q->whereIn('indicator_id', $indicatorIds)
        )->with('accomplishment:id,indicator_id')->get();

        foreach ($attachments as $file) {
            $events[] = [
                'kind'   => 'evidence',
                'at'     => $file->created_at,
                'actor'  => $file->uploaded_by_name,
                'detail' => $file->original_name,
                'line'   => Html::toText(
                    PcrIndicator::where('id', $file->accomplishment?->indicator_id)->value('description')
                ),
            ];
        }

        $handedOut = PcrIndicator::with('output.form.owner:id,name')
            ->whereIn('parent_indicator_id', $indicatorIds)
            ->get();

        foreach ($handedOut as $child) {
            $events[] = [
                'kind'   => 'delegation',
                'at'     => $child->created_at,
                'actor'  => $child->assigned_by_name,
                'detail' => $child->output?->form?->owner?->name,
                'line'   => Html::toText($child->description),
            ];
        }

        $handedHeadings = PcrOutput::with('form.owner:id,name')
            ->whereIn('parent_output_id', $outputIds)
            ->get();

        foreach ($handedHeadings as $child) {
            $events[] = [
                'kind'   => 'delegation',
                'at'     => $child->created_at,
                'actor'  => $child->assigned_by_name,
                'detail' => $child->form?->owner?->name,
                'line'   => $child->title,
            ];
        }

        $completed = PcrIndicator::whereIn('id', $indicatorIds)
            ->whereNotNull('completed_on')
            ->get(['id', 'description', 'completed_on']);

        foreach ($completed as $line) {
            $events[] = [
                'kind'   => 'progress',
                'at'     => $line->completed_on,
                'actor'  => $keeper,
                'detail' => 'Marked complete',
                'line'   => Html::toText($line->description),
            ];
        }

        usort($events, fn ($a, $b) => ($b['at'] <=> $a['at']));

        return response()->json([
            'events' => array_slice($events, 0, 300),
            'stages' => [
                'opened_at'      => PcrStatusLog::where('form_id', $form->id)->min('created_at'),
                'submitted_at'   => $form->submitted_at,
                'published_at'   => PcrStatusLog::where('form_id', $form->id)
                    ->where('to_status', 'published')->min('created_at'),
                'reviewed_at'    => $form->reviewed_at,
                'vp_reviewed_at' => $form->vp_reviewed_at,
                'rated_at'       => $form->rated_at,
                'closed_at'      => PcrStatusLog::where('form_id', $form->id)
                    ->where('to_status', 'final')->min('created_at'),
                'status'         => $form->status,
            ],
        ]);
    }

    /**
     * The OPCR targets an IPCR in this office and cycle may be linked to.
     * Empty when the office has not opened an OPCR yet.
     */
    public function opcrTargets(Request $request, $id)
    {
        $form = PcrForm::findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        return response()->json($this->targetsFor($form));
    }

    /**
     * The college's MFO/PPAs, for an IPCR to commit under. An individual does
     * not invent headings: they pick one the college already named, so
     * "Research" on somebody's IPCR is demonstrably the college's Research
     * programme rather than a phrase that happens to match.
     *
     * Support Functions are excluded — the OPCR never lists "Submission of DTR".
     */
    public function opcrOutputs(Request $request, $id)
    {
        $form = PcrForm::findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $opcr = $this->publishedOpcrFor($form);

        if (! $opcr || $form->type !== 'ipcr') {
            return response()->json([]);
        }

        return response()->json(
            $opcr->outputs
                ->whereIn('section', ['strategic', 'core'])
                ->map(fn ($output) => [
                    'id'      => $output->id,
                    'section' => $output->section,
                    'title'   => $output->title,
                ])
                ->values()
        );
    }

    /**
     * The published OPCR an IPCR answers to. One normally covers the whole
     * college; an organization may instead scope it per office.
     */
    /**
     * What still needs attention before this goes to QA. Better found here than
     * bounced back after somebody has read it.
     */
    public function readiness(Request $request, $id)
    {
        $form = PcrForm::with(['outputs.indicators.children', 'outputs.indicators.assignments', 'outputs.childOutputs'])
            ->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $issues = [];

        if ($form->outputs->isEmpty()) {
            $issues[] = 'Nothing has been committed to yet.';
        }

        foreach ($form->outputs as $output) {
            if (trim($output->title) === '' || str_contains($output->title, 'New MFO/PPA')) {
                $issues[] = "An MFO/PPA under {$output->section} still needs a name.";
            }

            if ($output->indicators->isEmpty() && ! PcrOutline::isHeader($output)) {
                $issues[] = "“{$output->title}” has no success indicator.";
            }

            foreach ($output->indicators as $line) {
                $text = trim(strip_tags($line->description));

                if ($text === '' || str_contains($text, 'New success indicator')) {
                    $issues[] = "A line under “{$output->title}” still needs its target and measure.";
                }

                // Support functions are the office head's own duties; the paper
                // form leaves their accountable column blank too.
                if (
                    $form->type === 'opcr'
                    && $output->section !== 'support'
                    && $line->assignments->isEmpty()
                    && $line->children->isEmpty()
                    && ! $line->accountable
                ) {
                    $issues[] = 'Nobody is accountable for: ' . mb_substr($text, 0, 60) . '…';
                }
            }
        }

        return response()->json([
            'ready'  => empty($issues),
            'issues' => array_values(array_unique($issues)),
        ]);
    }

    /**
     * An OPCR is largely the same year to year, so a new one can start from the
     * last. Structure only — accomplishments and ratings belong to their year.
     */
    public function copyFrom(Request $request, $id)
    {
        $data = $request->validate(['source_form_id' => 'required|integer|exists:pcr_forms,id']);

        $target = PcrForm::with('outputs')->findOrFail($id);
        $source = PcrForm::with('outputs.indicators')->findOrFail($data['source_form_id']);
        $user   = $request->user();

        if (! PcrWorkflow::owns($user, $target) || ! $target->isEditable()) {
            return response()->json([
                'message' => 'Only the owner can build this form, and only while it is a draft.',
            ], 409);
        }

        if ($message = PcrWorkflow::lockMessage($user, PcrWorkflow::periodForWrite($target))) {
            return response()->json(['message' => $message], 409);
        }

        if ($target->outputs->isNotEmpty()) {
            return response()->json([
                'message' => 'This form already has commitments. Clear them first to start from another year.',
            ], 409);
        }

        // A period-pinned IPCR copies every line into its own period; an OPCR
        // maps each source line's period onto the matching seq in its year.
        $targetPeriods = RatingPeriod::where('school_year_id', $target->school_year_id)
            ->orderBy('seq')
            ->pluck('id', 'seq');

        $sourceSeqs = RatingPeriod::where('school_year_id', $source->school_year_id)
            ->pluck('seq', 'id');

        $fallback = $targetPeriods->first();

        $periodFor = function ($line) use ($target, $targetPeriods, $sourceSeqs, $fallback) {
            if ($target->rating_period_id) {
                return $target->rating_period_id;
            }

            $seq = $line->rating_period_id ? ($sourceSeqs[$line->rating_period_id] ?? null) : null;

            return ($seq ? $targetPeriods[$seq] ?? null : null) ?? $fallback;
        };

        $copied = 0;

        DB::transaction(function () use ($source, $target, $periodFor, &$copied) {
            $idMap = [];

            foreach ($source->outputs as $output) {
                $new = PcrOutput::create([
                    'form_id'    => $target->id,
                    'section'    => $output->section,
                    'title'      => $output->title,
                    'sort_order' => $output->sort_order,
                ]);

                $idMap[$output->id] = $new->id;

                foreach ($output->indicators as $line) {
                    PcrIndicator::create([
                        'output_id'        => $new->id,
                        'rating_period_id' => $periodFor($line),
                        'description'      => $line->description,
                        'allotted_budget'  => $line->allotted_budget,
                        'accountable'      => $line->accountable,
                        'sort_order'       => $line->sort_order,
                    ]);

                    $copied++;
                }
            }

            foreach ($source->outputs as $output) {
                if (! $output->parent_output_id || ! isset($idMap[$output->parent_output_id])) {
                    continue;
                }

                PcrOutput::where('id', $idMap[$output->id])->update([
                    'parent_output_id' => $idMap[$output->parent_output_id],
                ]);
            }
        });

        ActivityLog::record('PcrForm', $target->id, 'create', "Started from {$source->schoolYear?->label}");

        return response()->json(['data' => 'copied', 'lines' => $copied]);
    }

    private function publishedOpcrFor(PcrForm $form): ?PcrForm
    {
        return PcrForm::with(['outputs.indicators', 'outputs.parentOutput.parentOutput'])
            ->where('type', 'opcr')
            ->where('school_year_id', $form->school_year_id)
            ->when(
                ! app(WorkflowSettings::class)->opcrOnePerOrganization(),
                fn ($q) => $q->where('org_unit_id', $form->org_unit_id)
            )
            ->whereIn('status', PcrWorkflow::OPCR_VISIBLE)
            ->first();
    }

    private function targetsFor(PcrForm $form)
    {
        if ($form->type !== 'ipcr') {
            return collect();
        }

        $assigned    = $this->assignmentsFor($form);
        $assignedIds = $assigned->pluck('indicator_id');
        $opcr        = $this->publishedOpcrFor($form);
        $fromOpcr    = collect();

        if ($opcr) {
            $fromOpcr = $opcr->outputs->flatMap(function ($output) use ($form, $assignedIds) {
                return $output->indicators
                    ->filter(fn ($indicator) => ! $form->rating_period_id
                        || ! $indicator->rating_period_id
                        || (int) $indicator->rating_period_id === (int) $form->rating_period_id)
                    ->map(fn ($indicator) => [
                        'id'               => $indicator->id,
                        'section'          => $output->section,
                        'output_id'        => $output->id,
                        'output_title'     => $this->outputPath($output),
                        'description'      => $indicator->description,
                        'rating_period_id' => $indicator->rating_period_id,
                        'assigned'         => $assignedIds->contains($indicator->id),
                    ]);
            });
        }

        $fromPeople = $assigned
            ->reject(fn ($assignment) => $assignment->indicator->output->form->type === 'opcr')
            ->map(fn ($assignment) => [
                'id'               => $assignment->indicator_id,
                'section'          => $assignment->indicator->output->section,
                'output_id'        => $assignment->indicator->output_id,
                'output_title'     => $this->outputPath($assignment->indicator->output),
                'description'      => $assignment->indicator->description,
                'rating_period_id' => $assignment->rating_period_id,
                'assigned'         => true,
                'assigned_by_name' => $assignment->assigned_by_name,
            ]);

        if ($form->picksAssignedTargetsOnly()) {
            return $fromPeople->values();
        }

        return $fromPeople->concat($fromOpcr)->unique('id')->values();
    }

    private function assignedTargetsFor(PcrForm $form): array
    {
        $assignments = $this->assignmentsFor($form);

        if ($form->picksAssignedTargetsOnly()) {
            $assignments = $assignments->reject(
                fn ($assignment) => $assignment->indicator->output->form->type === 'opcr'
            );
        }

        return $assignments
            ->map(fn ($assignment) => [
                'id'               => $assignment->indicator_id,
                'assignment_id'    => $assignment->id,
                'section'          => $assignment->indicator->output->section,
                'output_title'     => $this->outputPath($assignment->indicator->output),
                'description'      => $assignment->indicator->description,
                'assigned_by_name' => $assignment->assigned_by_name,
            ])
            ->values()
            ->all();
    }

    /**
     * Targets handed to this person, whether they sit on the college OPCR or on
     * a head or VP IPCR. The form they already own is not one of them.
     */
    private function assignmentsFor(PcrForm $form)
    {
        if ($form->type !== 'ipcr' || ! $form->user_id) {
            return collect();
        }

        return PcrTargetAssignment::with(['indicator.output.form', 'indicator.output.parentOutput'])
            ->where('user_id', $form->user_id)
            ->whereHas('indicator.output.form', function ($query) use ($form) {
                $query->where('school_year_id', $form->school_year_id)
                    ->where('id', '!=', $form->id);
            })
            ->when(
                $form->rating_period_id,
                fn ($query) => $query->where(function ($inner) use ($form) {
                    $inner->whereNull('rating_period_id')
                        ->orWhere('rating_period_id', $form->rating_period_id);
                })
            )
            ->get()
            ->filter(fn ($assignment) => $assignment->indicator?->output?->form)
            ->unique('indicator_id')
            ->values();
    }

    private function outputPath(PcrOutput $output): string
    {
        $titles = [$output->title];
        $seen   = [$output->id => true];
        $current = $output->relationLoaded('parentOutput') ? $output->parentOutput : $output->parentOutput()->first();

        while (
            $current
            && (int) $current->form_id === (int) $output->form_id
            && ! isset($seen[$current->id])
        ) {
            array_unshift($titles, $current->title);
            $seen[$current->id] = true;
            $current = $current->parentOutput;
        }

        return implode(' — ', $titles);
    }

    /**
     * Lines the writer reports themselves. Work handed on is finished by the
     * people named under it, so it is not this writer's narrative to supply.
     */
    private function unfinishedWriteUp(PcrForm $form): ?string
    {
        $lines = $form->indicators()
            ->withCount(['children', 'assignments'])
            ->with(['accomplishments' => fn ($query) => $query->withCount('attachments')])
            ->get();

        $gaps = [];

        foreach ($lines as $line) {
            if ((int) $line->children_count > 0 || (int) $line->assignments_count > 0) {
                continue;
            }

            $records = $line->accomplishments;

            if ($line->rating_period_id) {
                $records = $records->where('rating_period_id', (int) $line->rating_period_id);
            }

            $done = $records->contains(
                fn ($record) => ! Html::isBlank($record->actual_accomplishment)
                    && (int) $record->attachments_count > 0
            );

            if ($done) {
                continue;
            }

            $hasNarrative = $records->contains(
                fn ($record) => ! Html::isBlank($record->actual_accomplishment)
            );
            $hasFile = $records->contains(
                fn ($record) => (int) $record->attachments_count > 0
            );

            $text = trim(Html::toText($line->description));
            $name = $text === '' ? 'A line' : '“' . mb_strimwidth($text, 0, 60, '…') . '”';

            if ($hasNarrative && ! $hasFile) {
                $gaps[] = "{$name} still needs a file.";
            } elseif ($hasFile && ! $hasNarrative) {
                $gaps[] = "{$name} still needs a narrative.";
            } else {
                $gaps[] = "{$name} still needs a narrative and a file.";
            }
        }

        if ($gaps === []) {
            return null;
        }

        $shown = array_slice($gaps, 0, 3);
        $rest  = count($gaps) - count($shown);
        $message = 'Finish the write-up before submitting. ' . implode(' ', $shown);

        if ($rest > 0) {
            $message .= ' ' . $rest . ' more line' . ($rest === 1 ? ' is' : 's are') . ' still incomplete.';
        }

        return $message;
    }

    private function hasUnratedLines(PcrForm $form): bool
    {
        $ids = PcrIndicator::query()
            ->whereHas('output', fn ($q) => $q->where('form_id', $form->id))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return false;
        }

        $rated = PcrRating::query()
            ->whereIn('indicator_id', $ids)
            ->when($form->rating_period_id, fn ($q) => $q->where('rating_period_id', $form->rating_period_id))
            ->whereNotNull('q')
            ->whereNotNull('e')
            ->whereNotNull('t')
            ->pluck('indicator_id')
            ->unique();

        return $rated->count() < $ids->count();
    }

    /** Human notes for stages the form jumped over on this transition. */
    private static function skippedBetween(PcrForm $form, string $from, string $to): array
    {
        if ($form->type !== 'ipcr') {
            return [];
        }

        $notes = [];

        $passedHead = in_array($from, ['draft', 'returned'], true)
            && in_array($to, ['vp_review', 'qa_rating'], true);

        if ($passedHead && ! $form->head_reviewer_id) {
            $notes[] = 'Head review skipped — the ratee heads this office, or it has no separate head.';
        }

        $passedVp = in_array($from, ['draft', 'returned', 'head_review'], true) && $to === 'qa_rating';

        if ($passedVp && ! $form->vp_reviewer_id) {
            $notes[] = 'VP review skipped — no separate VP above this ratee.';
        }

        return $notes;
    }

    private function scopeToOwner($query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $rules     = app(WorkflowSettings::class);
        $opensOpcr = in_array($user->role, $rules->opcr('creator_roles'), true)
            || in_array($user->role, $rules->opcr('publisher_roles'), true);

        $query->where(function ($q) use ($user, $opensOpcr) {
            $q->where('user_id', $user->id);

            if ($opensOpcr) {
                $q->orWhere('type', 'opcr');
            } elseif ($user->role === 'program_head' && $user->org_unit_id) {
                $q->orWhere(function ($x) use ($user) {
                    $x->where('type', 'opcr')->where('org_unit_id', $user->org_unit_id);
                });
            }
        });
    }

    /**
     * The president's own OPCR should not list him again as a person who
     * delivered nothing. His write-up lives on the office line itself.
     */
    private function hidePresidentZeroOnHisOpcr(PcrForm $form): void
    {
        if ($form->type !== 'opcr') {
            return;
        }

        foreach ($form->outputs as $output) {
            foreach ($output->indicators as $indicator) {
                if (! $indicator->relationLoaded('children')) {
                    continue;
                }

                $indicator->setRelation('children', $indicator->children->reject(function ($child) {
                    $owner = $child->output?->form?->owner;

                    return $owner
                        && $owner->role === 'president'
                        && (int) ($child->progress_pct ?? 0) === 0;
                })->values());
            }
        }
    }

    private function scopeToViewer($query, User $user, bool $queueOnly): void
    {
        if (in_array($user->role, ['admin', 'qa', 'president'], true)) {
            if ($user->role === 'president' && $queueOnly) {
                // The president does not review — QA does.
                $query->whereRaw('1 = 0');

                return;
            }

            if ($user->role === 'qa' && $queueOnly) {
                $approves = in_array('qa', app(WorkflowSettings::class)->opcr('approver_roles'), true);
                $waiting  = $approves ? ['qa_rating', 'qa_approval'] : ['qa_rating'];

                // Staff IPCRs stay with the QA director. They are not sent upward.
                $query->where(function ($q) use ($user, $waiting) {
                    $q->whereIn('status', $waiting)
                        ->orWhere(function ($inner) use ($user) {
                            $inner->where('status', 'head_review')->where('head_reviewer_id', $user->id);
                        });
                });
            }

            return;
        }

        // Units this person oversees in either capacity — one person may hold
        // both a head and a VP post, so this is a union, not a role lookup.
        $unitIds = collect(OrgUnit::overseenIds((int) $user->id));

        if ($unitIds->isNotEmpty() || in_array($user->role, ['program_head', 'vp'], true)) {
            if ($queueOnly) {
                // Authority is being named on the form, not holding a role.
                $query->where(function ($q) use ($user) {
                    $q->where(function ($x) use ($user) {
                        $x->where('head_reviewer_id', $user->id)->where('status', 'head_review');
                    })->orWhere(function ($x) use ($user) {
                        $x->where('vp_reviewer_id', $user->id)->where('status', 'vp_review');
                    });
                })->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id);
                });

                return;
            }

            $query->where(function ($q) use ($unitIds, $user) {
                $q->whereIn('org_unit_id', $unitIds)
                    ->orWhere('user_id', $user->id)
                    ->orWhere('head_reviewer_id', $user->id)
                    ->orWhere('vp_reviewer_id', $user->id);
            });

            return;
        }

        $query->where('user_id', $user->id);
    }
}
