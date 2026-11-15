<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\PcrAttachment;
use App\Models\PcrComment;
use App\Models\PcrForm;
use App\Models\PcrFormReviewer;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrRating;
use App\Models\PcrReturnMark;
use App\Models\RatingPeriod;
use App\Models\PcrStatusLog;
use App\Models\PcrTargetAssignment;
use App\Models\User;
use App\Services\IndicatorProgressService;
use App\Services\PcrWorkflow;
use App\Services\WorkflowSettings;
use App\Support\Html;
use App\Support\PcrOutline;
use App\Support\PcrPrint;
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
            'stageReviewers',
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

        if ($request->boolean('accountable')) {
            $query->where('type', 'opcr')->accountableTo((int) $user->id);
        } elseif ($oversight = $request->query('oversight')) {
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $statuses = match ($oversight) {
                'review' => array_values(array_unique(array_merge(
                    ['commitment_review', 'head_review', 'dean_review', 'vp_review', 'qa_approval', 'returned'],
                    array_diff(PcrWorkflow::stageStatuses(), ['qa_rating'])
                ))),
                'rating' => ['qa_rating', 'rated', 'final'],
                default  => null,
            };

            if ($statuses === null) {
                return response()->json(['message' => 'Unknown list.'], 422);
            }

            $query->whereIn('status', $statuses);
        } elseif ($request->boolean('mine')) {
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
            'stageReviewers',
            'orgUnit.reviewers',
            'orgUnit.head:id,name,position_title',
            'orgUnit.dean:id,name,position_title',
            'orgUnit.vp:id,name,position_title',
            'owner:id,name,position_title,image',
            'headReviewer:id,name,position_title',
            'deanReviewer:id,name,position_title',
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
        $payload['review_steps'] = $form->type === 'ipcr' ? PcrWorkflow::chainFor($form) : [];

        if ($form->type === 'ipcr') {
            $approver = $form->status === 'commitment_review' && $form->commitment_approver_id
                ? User::find($form->commitment_approver_id)
                : (! $form->commitment_approved_at ? PcrWorkflow::commitmentApprover($form) : null);

            $payload['commitment_approver'] = $approver ? [
                'id'             => $approver->id,
                'name'           => $approver->name,
                'position_title' => $approver->position_title,
                'label'          => PcrWorkflow::commitmentApproverLabel($approver),
            ] : null;
            $payload['commitment_wait'] = $approver || $form->commitment_approved_at
                ? null
                : PcrWorkflow::commitmentWait($form);
        }

        if ($form->type === 'ipcr' && $form->user_id) {
            $payload['assigned_targets'] = $this->assignedTargetsFor($form);
        }

        if ($form->type === 'opcr') {
            $payload['rating_window_open'] = PcrWorkflow::opcrRatingMonthOpen($form);
            $head = PcrPrint::officeHead();
            $payload['office_head'] = $head?->only(['id', 'name', 'position_title']);
        }

        $payload['return_marks'] = $this->openReturnMarks($form);

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
                    'message' => PcrWorkflow::commitmentClosedMessage($form)
                        ?? 'This form can only be edited while it is a draft or has been returned to you.',
                ], 409);
            }

            if ($message = PcrWorkflow::formLockMessage($user, $form)) {
                return response()->json(['message' => $message], 409);
            }

            $form->update(['header_note' => $data['header_note'] ?? null]);

            return response()->json(['data' => 'updated', 'form' => $form]);
        }

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'An administrator can view forms, not create them.',
            ], 403);
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

        if ($data['type'] === 'ipcr' && (int) $ownerId !== (int) $user->id) {
            return response()->json(['message' => 'You can only create your own IPCR.'], 403);
        }

        if ($data['type'] === 'ipcr' && User::whereKey($ownerId)->where('role', 'president')->exists()) {
            return response()->json([
                'message' => 'The president files the college OPCR, not an individual IPCR.',
            ], 422);
        }

        if ($data['type'] === 'opcr') {
            $rules = app(WorkflowSettings::class);

            if (! in_array($user->role, $rules->opcr('creator_roles'), true)) {
                return response()->json([
                    'message' => 'Your role does not open the college OPCR.',
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

        $directs = $unit->reviewers()
            ->where('slot', 'director_review')
            ->where('user_id', $user->id)
            ->exists();

        if (! $directs
            && (int) $unit->head_user_id !== (int) $user->id
            && (int) $unit->dean_user_id !== (int) $user->id
            && (int) $unit->vp_user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Only the head, dean, director, or VP of that office can open its IPCRs.',
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
            ->whereNotIn('role', ['admin', 'president'])
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

        if (($template['for'] ?? null) && $template['for'] !== $form->type) {
            return response()->json(['message' => 'That template does not belong on this form.'], 422);
        }

        $roles  = $template['roles'] ?? null;
        $except = $template['except_roles'] ?? [];

        if (($roles && ! in_array($user->role, $roles, true)) || in_array($user->role, $except, true)) {
            return response()->json(['message' => 'That template does not belong on this form.'], 422);
        }

        if (! PcrWorkflow::owns($user, $form) || ! PcrWorkflow::canEditCommitments($user, $form)) {
            return response()->json([
                'message' => PcrWorkflow::commitmentClosedMessage($form)
                    ?? 'Only the owner can build this form, and only while it is a draft or has been returned.',
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
            'status' => ['required', 'string', 'max:40'],
            'note'   => 'nullable|string|max:500',
            'fixes'  => 'nullable|array|max:80',
            'fixes.*.output_id'    => 'nullable|integer',
            'fixes.*.indicator_id' => 'nullable|integer',
            'fixes.*.field'        => 'nullable|string|max:40',
            'fixes.*.note'         => 'nullable|string|max:500',
        ]);

        if (! in_array($data['status'], array_unique(array_merge(
            PcrForm::STATUSES,
            PcrWorkflow::stageStatuses()
        )), true)) {
            return response()->json(['message' => 'That is not a step in the review chain.'], 422);
        }

        $user = $request->user();
        $form = PcrForm::with('orgUnit')->findOrFail($id);
        $from = $form->status;
        $to   = $data['status'];

        if ($from === $to) {
            return response()->json(['message' => "This form is already {$to}."], 409);
        }

        $isCommitmentSubmit = false;
        $isCommitmentDecision = false;

        // Commitments are approved before rating. Asking to skip ahead still
        // lands on the approver, never on the head.
        if ($form->type === 'ipcr' && $from === 'commitment_review') {
            if ((int) $form->commitment_approver_id !== (int) $user->id) {
                return response()->json([
                    'message' => 'This form is waiting for somebody else to approve the commitments.',
                ], 403);
            }

            if (! in_array($to, ['commitments_approved', 'returned'], true)) {
                return response()->json([
                    'message' => 'These commitments can be approved or returned.',
                ], 409);
            }

            $isCommitmentDecision = true;
        }

        if (! $isCommitmentDecision
            && $form->type === 'ipcr'
            && ! $form->commitment_approved_at
            && in_array($from, ['draft', 'returned'], true)
            && PcrWorkflow::owns($user, $form)
            && ($to === 'commitment_review' || PcrWorkflow::isReviewDestination($to))
        ) {
            if ($form->indicators()->count() === 0) {
                return response()->json([
                    'message' => 'Add at least one success indicator before submitting.',
                ], 422);
            }

            $approver = PcrWorkflow::commitmentApprover($form);

            if (! $approver) {
                return response()->json([
                    'message' => PcrWorkflow::commitmentWait($form)
                        ?? 'There is nobody to approve these commitments.',
                ], 422);
            }

            $to = 'commitment_review';
            $form->forceFill(['commitment_approver_id' => $approver->id])->save();
            $isCommitmentSubmit = true;
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
        if ($form->type === 'ipcr'
            && $form->commitment_approved_at
            && in_array($from, ['commitments_approved', 'returned'], true)
            && PcrWorkflow::isReviewDestination($to)
            && PcrWorkflow::owns($user, $form)
        ) {
            $form->forceFill(PcrWorkflow::resolveReviewers($form))->save();
            $to = PcrWorkflow::nextStatusFor($form, $from) ?? $to;
            $isOwnerSubmission = true;
        }

        // The client may still ask for approval. A form that already left that
        // lane is sent back to rating instead.
        if ($form->type === 'opcr' && $from === 'returned') {
            $to = PcrWorkflow::opcrResubmitStatus($form);
        }

        $isReviewStage = $form->type === 'ipcr'
            && PcrWorkflow::isReviewDestination($from)
            && $from !== 'qa_rating';

        if ($isOwnerSubmission || $isCommitmentSubmit || $isCommitmentDecision) {
            // Submitting your own form is authorised by owning it. Approving
            // commitments is authorised by being the named approver.
        } elseif ($isReviewStage) {
            // Authority comes from being named on this form, not from a role
            // label — which is what lets one person hold both posts.
            if (! PcrWorkflow::isReviewerFor($user, $form, $from)) {
                return response()->json([
                    'message' => 'This form is waiting for somebody else to review it.',
                ], 403);
            }
        } elseif (! PcrWorkflow::actorMayMoveTo($user, $form->type, $to, $from)) {
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

        $isSubmission = $form->commitment_approved_at
            && in_array($from, ['commitments_approved', 'returned'], true)
            && PcrWorkflow::isReviewDestination($to);

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
            if (PcrWorkflow::isRater($form->owner)) {
                $to = 'qa_rating';
                $form->forceFill([
                    'head_reviewer_id' => null,
                    'dean_reviewer_id' => null,
                    'vp_reviewer_id'   => null,
                ])->save();
                PcrFormReviewer::where('form_id', $form->id)->update(['reviewer_id' => null]);
            }
        }

        if ($from === 'head_review' && $to !== 'returned') {
            $form->loadMissing('headReviewer');

            if (PcrWorkflow::isRater($form->headReviewer)) {
                return response()->json([
                    'message' => 'Rate this IPCR and finalize it. It is not sent on to a dean, a director, a VP, or the president.',
                ], 409);
            }

            $to = PcrWorkflow::nextStatusFor($form, $from) ?? $to;
        }

        if ($from === 'dean_review' && $to !== 'returned') {
            $form->loadMissing('deanReviewer');

            if (PcrWorkflow::isRater($form->deanReviewer)) {
                return response()->json([
                    'message' => 'Rate this IPCR and finalize it. It is not sent on to a VP or the president.',
                ], 409);
            }

            $to = PcrWorkflow::nextStatusFor($form, $from) ?? $to;
        }

        if ($form->type === 'ipcr'
            && PcrWorkflow::isReviewDestination($from)
            && ! in_array($from, ['head_review', 'dean_review', 'qa_rating'], true)
            && $to !== 'returned'
        ) {
            $to = PcrWorkflow::nextStatusFor($form, $from) ?? $to;
        }

        if ($form->type === 'ipcr'
            && PcrWorkflow::isReviewDestination($from)
            && $from !== 'qa_rating'
            && $to !== 'returned'
            && $this->hasUnratedLines($form)
        ) {
            return response()->json([
                'message' => 'Rate every line before sending this on.',
            ], 422);
        }

        $fixes = [];

        // Approval returns name the target cells. A return from review or
        // rating names the accomplishment or the evidence.
        if ($this->namesReturnPlaces($form, $from, $to)) {
            $fixes = $this->returnPlaces($form, $from, $data['fixes'] ?? []);

            if ($fixes instanceof \Illuminate\Http\JsonResponse) {
                return $fixes;
            }

            $data['note'] = implode("\n", array_column($fixes, 'note'));
        }

        $data['note'] = Html::clean($data['note'] ?? null);

        if ($to === 'returned' && Html::isBlank($data['note'])) {
            return response()->json([
                'message' => 'Say what needs fixing before returning the form.',
            ], 422);
        }

        DB::transaction(function () use ($form, $from, $to, $data, $user, $isSubmission, $isCommitmentSubmit, $fixes) {
            $attributes = ['status' => $to];

            if ($isSubmission || $isCommitmentSubmit) {
                $attributes['submitted_at'] = now();
            }

            if ($from === 'commitment_review' && $to === 'commitments_approved') {
                $attributes['commitment_approved_by']      = $user->id;
                $attributes['commitment_approved_by_name'] = $user->name;
                $attributes['commitment_approved_at']      = now();
            }

            $form->update($attributes);

            if ($to !== 'returned' && PcrWorkflow::isReviewDestination($from) && $from !== 'qa_rating') {
                PcrFormReviewer::where('form_id', $form->id)->where('stage', $from)->update([
                    'reviewed_by'      => $user->id,
                    'reviewed_by_name' => $user->name,
                    'reviewed_at'      => now(),
                ]);
            }
            $log = PcrStatusLog::record($form->id, $from, $to, Html::toText($data['note'] ?? null) ?: null);

            if ($log && $fixes) {
                foreach ($fixes as $fix) {
                    PcrReturnMark::create([
                        'form_id'       => $form->id,
                        'status_log_id' => $log->id,
                        'output_id'     => $fix['output_id'],
                        'indicator_id'  => $fix['indicator_id'],
                        'field'         => $fix['field'],
                        'note'          => $fix['note'],
                    ]);
                }
            }

            // Say so when a stage was passed over, so the trail stays honest
            // about who actually saw the form.
            foreach (self::skippedBetween($form, $from, $to) as $skipped) {
                PcrStatusLog::record($form->id, $from, $to, $skipped);
            }
        });

        ActivityLog::record('PcrForm', $form->id, 'status', "Form moved from {$from} to {$to}");
        $this->notifyTransition($form, $from, $to, $data['note'] ?? null, $fixes);

        return response()->json(['data' => 'updated', 'form' => $form->fresh()]);
    }

    /**
     * Marks from the latest return, shown only while the form is still back
     * with the writer. A later resubmit leaves them in the log.
     *
     * @return array<int, array<string, mixed>>
     */
    private function openReturnMarks(PcrForm $form): array
    {
        if ($form->status !== 'returned') {
            return [];
        }

        $log = $form->statusLogs->where('to_status', 'returned')->sortByDesc('id')->first();

        if (! $log) {
            return [];
        }

        return PcrReturnMark::where('status_log_id', $log->id)
            ->orderBy('id')
            ->get(['id', 'output_id', 'indicator_id', 'field', 'note'])
            ->map(fn (PcrReturnMark $mark) => $mark->only(['id', 'output_id', 'indicator_id', 'field', 'note']))
            ->all();
    }

    private function namesReturnPlaces(PcrForm $form, string $from, string $to): bool
    {
        if ($to !== 'returned') {
            return false;
        }

        if ($form->type === 'ipcr') {
            return $from === 'commitment_review' || PcrWorkflow::isReviewDestination($from);
        }

        return in_array($from, ['qa_approval', 'qa_rating'], true);
    }

    /**
     * Approval returns point at the targets. A return from review or rating
     * points at the write-up, because the targets stay as they were approved.
     *
     * @return array<int, string>
     */
    private function returnFields(PcrForm $form, string $from): array
    {
        if ($this->writeupReturn($form, $from)) {
            return ['actual_accomplishment', 'evidence'];
        }

        $fields = ['title', 'description', 'target_date'];

        if ($form->type === 'opcr') {
            $fields[] = 'allotted_budget';
            $fields[] = 'accountable';
        }

        return $fields;
    }

    private function writeupReturn(PcrForm $form, string $from): bool
    {
        if ($form->type === 'ipcr') {
            return PcrWorkflow::isReviewDestination($from);
        }

        return $from === 'qa_rating';
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>|\Illuminate\Http\JsonResponse
     */
    private function returnPlaces(PcrForm $form, string $from, array $rows)
    {
        if ($rows === []) {
            return response()->json([
                'message' => 'Choose at least one place that needs fixing.',
            ], 422);
        }

        $allowed    = $this->returnFields($form, $from);
        $outputIds  = PcrOutput::where('form_id', $form->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $indicators = PcrIndicator::whereIn('output_id', $outputIds)->get(['id', 'output_id'])->keyBy('id');
        $places     = [];
        $seen       = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return response()->json(['message' => 'Each place needs a field and what to fix.'], 422);
            }

            $field = (string) ($row['field'] ?? '');
            $note  = Html::toText(Html::clean($row['note'] ?? null));

            if (! in_array($field, $allowed, true) || $note === '') {
                return response()->json([
                    'message' => $this->writeupReturn($form, $from) && $field !== '' && ! in_array($field, $allowed, true)
                        ? 'A return from review can only mark the accomplishment or the evidence.'
                        : (in_array($field, ['allotted_budget', 'accountable'], true) && $form->type !== 'opcr'
                            ? 'Budget and accountable can be marked on an OPCR only.'
                            : 'Each place needs a field and what to fix.'),
                ], 422);
            }

            if ($field === 'title') {
                $outputId = (int) ($row['output_id'] ?? 0);

                if (! in_array($outputId, $outputIds, true)) {
                    return response()->json(['message' => 'That output is not part of this form.'], 422);
                }

                $place = [
                    'output_id'    => $outputId,
                    'indicator_id' => null,
                    'field'        => $field,
                    'note'         => mb_substr($note, 0, 500),
                ];
                $key = "output:{$outputId}:title";
            } else {
                $indicator = $indicators->get((int) ($row['indicator_id'] ?? 0));

                if (! $indicator) {
                    return response()->json(['message' => 'That success indicator is not part of this form.'], 422);
                }

                $place = [
                    'output_id'    => (int) $indicator->output_id,
                    'indicator_id' => (int) $indicator->id,
                    'field'        => $field,
                    'note'         => mb_substr($note, 0, 500),
                ];
                $key = "indicator:{$indicator->id}:{$field}";
            }

            if (isset($seen[$key])) {
                return response()->json(['message' => 'Each place can be named once.'], 422);
            }

            $seen[$key] = true;
            $places[]   = $place;
        }

        return $places;
    }

    /**
     * The first marked place, so a return opens on that line or heading.
     *
     * @param  array<int, array<string, mixed>>  $places
     */
    private function placeLink(PcrForm $form, array $places = []): string
    {
        foreach ($places as $place) {
            if (! empty($place['indicator_id'])) {
                return "/forms/{$form->id}?line={$place['indicator_id']}";
            }
        }

        foreach ($places as $place) {
            if (! empty($place['output_id'])) {
                return "/forms/{$form->id}?output={$place['output_id']}";
            }
        }

        return "/forms/{$form->id}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $places
     */
    private function notifyTransition(PcrForm $form, string $from, string $to, ?string $note, array $places = []): void
    {
        $note = Html::toText($note) ?: null;
        $returnLink = $this->placeLink($form, $places);

        $label   = strtoupper($form->type);
        $subject = $form->owner?->name ?? $form->orgUnit?->name;

        if ($to === 'commitment_review') {
            Notification::send(
                $form->commitment_approver_id,
                'approval',
                "IPCR commitments from {$subject} need your approval",
                'They are waiting in your review queue.',
                $form->id
            );

            return;
        }

        if ($from === 'commitment_review' && $to === 'commitments_approved') {
            Notification::send(
                $form->user_id,
                'approved',
                'Your commitments were approved',
                'You can now hand work to your team and record your tasks.',
                $form->id
            );

            return;
        }

        if ($from === 'commitment_review' && $to === 'returned') {
            Notification::send(
                $form->user_id,
                'returned',
                'Your commitments were returned for correction',
                $note,
                $form->id,
                $returnLink
            );

            return;
        }

        $this->notifyOwnerTheirRatingMovedOn($form, $from, $to);

        if ($to === 'head_review') {
            Notification::send(
                $form->head_reviewer_id,
                'review',
                "{$label} from {$subject} needs your review",
                'It is waiting in your review queue.',
                $form->id
            );

            return;
        }

        if ($to === 'dean_review') {
            Notification::send(
                $form->dean_reviewer_id,
                'review',
                "{$label} from {$subject} needs your review",
                'It is waiting in your review queue.',
                $form->id
            );

            return;
        }

        if ($to === 'vp_review') {
            Notification::send(
                $form->vp_reviewer_id,
                'review',
                "{$label} from {$subject} needs your review",
                'It has been endorsed and is waiting in your review queue.',
                $form->id
            );

            return;
        }

        if ($form->type === 'ipcr' && PcrWorkflow::isReviewDestination($to) && $to !== 'qa_rating') {
            Notification::send(
                PcrWorkflow::reviewerId($form, $to),
                'review',
                "{$label} from {$subject} needs your review",
                'It is waiting in your review queue.',
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
                $this->usersWithRoles(app(WorkflowSettings::class)->raterRoles()),
                'rating',
                "{$label} from {$subject} is ready for rating",
                'It is waiting for a rating.',
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
                $form->id,
                $returnLink
            );

            // The writer already has the correction. The head and VP only need
            // to know it went back, and not the person who just sent it back.
            if ($form->type === 'ipcr') {
                $ownerId = $form->user_id ? (int) $form->user_id : null;

                Notification::sendMany(
                    array_filter(
                        [$form->head_reviewer_id, $form->dean_reviewer_id, $form->vp_reviewer_id],
                        fn ($id) => $id && (int) $id !== $ownerId
                    ),
                    'returned',
                    "{$label} from {$subject} was returned for correction",
                    $note,
                    $form->id,
                    $returnLink
                );
            }
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
            $from === 'head_review' && $to === 'dean_review' => 'the dean',
            in_array($from, ['head_review', 'dean_review'], true) && $to === 'director_review' => 'the director',
            in_array($from, ['head_review', 'dean_review', 'director_review'], true) && $to === 'vp_review' => 'the VP',
            in_array($from, ['head_review', 'dean_review', 'director_review', 'vp_review'], true) && $to === 'qa_rating' => 'QA',
            PcrWorkflow::isReviewDestination($from) && $from !== 'qa_rating' && PcrWorkflow::isReviewDestination($to)
                => preg_replace('/^With /i', 'the ', (string) $form->status_label) ?: 'the next reviewer',
            default => null,
        };

        if (! $sentTo) {
            return;
        }

        $by = match ($from) {
            'dean_review'      => 'The dean has rated it and sent it on.',
            'director_review'  => 'The director has rated it and sent it on.',
            'vp_review'   => 'The VP has rated it and sent it on.',
            default       => 'The head has rated it and sent it on.',
        };

        Notification::send(
            $form->user_id,
            'review',
            "Your IPCR was rated and forwarded to {$sentTo}",
            $by,
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
                $ipcr?->id ?? $form->id
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

        if (! ($form->status === 'draft' && PcrWorkflow::owns($user, $form))) {
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
                'reviewed_at'      => $form->reviewed_at,
                'dean_reviewed_at' => $form->dean_reviewed_at,
                'vp_reviewed_at'   => $form->vp_reviewed_at,
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
            if (
                trim($output->title) === ''
                || $output->title === 'New PPA'
                || str_contains($output->title, 'New MFO/PPA')
            ) {
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
     * An OPCR can start from last year's structure. An IPCR can start from the
     * owner's other review period of this same year. Structure and the office
     * target each line already answers — accomplishments and ratings stay behind.
     */
    public function copyFrom(Request $request, $id)
    {
        $data = $request->validate(['source_form_id' => 'required|integer|exists:pcr_forms,id']);

        $target = PcrForm::with('outputs')->findOrFail($id);
        $source = PcrForm::with(['outputs.indicators', 'ratingPeriod', 'schoolYear'])->findOrFail($data['source_form_id']);
        $user   = $request->user();

        if (! PcrWorkflow::owns($user, $target) || ! $target->isEditable()) {
            return response()->json([
                'message' => 'Only the owner can build this form, and only while it is a draft.',
            ], 409);
        }

        if (! PcrWorkflow::canView($user, $source)) {
            return response()->json(['message' => 'You do not have access to that form.'], 403);
        }

        if ($target->type === 'ipcr' && ! $this->isEarlierIpcr($source, $target)) {
            return response()->json([
                'message' => 'Copy from your own IPCR for another review period of this year.',
            ], 422);
        }

        if ($message = PcrWorkflow::formLockMessage($user, $target)) {
            return response()->json(['message' => $message], 409);
        }

        if ($target->outputs->isNotEmpty()) {
            return response()->json([
                'message' => $target->type === 'ipcr'
                    ? 'This form already has commitments. Clear them first to copy a previous period.'
                    : 'This form already has commitments. Clear them first to start from another year.',
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
            $outputMap = [];
            $lineMap   = [];

            foreach ($source->outputs as $output) {
                $new = PcrOutput::create([
                    'form_id'          => $target->id,
                    'section'          => $output->section,
                    'title'            => $output->title,
                    'sort_order'       => $output->sort_order,
                    'assigned_by'      => $output->assigned_by,
                    'assigned_by_name' => $output->assigned_by_name,
                ]);

                $outputMap[$output->id] = $new->id;

                foreach ($output->indicators as $line) {
                    $created = PcrIndicator::create([
                        'output_id'        => $new->id,
                        'rating_period_id' => $periodFor($line),
                        'description'      => $line->description,
                        'target_date'      => $line->target_date,
                        'allotted_budget'  => $line->allotted_budget,
                        'accountable'      => $line->accountable,
                        'sort_order'       => $line->sort_order,
                    ]);

                    $lineMap[$line->id] = $created->id;
                    $copied++;
                }
            }

            foreach ($source->outputs as $output) {
                $parentId = $this->copiedParentOutputId($output->parent_output_id, $outputMap, $target);

                if (! $parentId) {
                    continue;
                }

                PcrOutput::where('id', $outputMap[$output->id])->update([
                    'parent_output_id' => $parentId,
                ]);
            }

            $officeParents = $this->officeParentsFor(array_keys($lineMap), $target);

            foreach ($source->outputs as $output) {
                foreach ($output->indicators as $line) {
                    $parentId = $this->copiedParentIndicatorId($line->parent_indicator_id, $lineMap, $officeParents);

                    if (! $parentId) {
                        continue;
                    }

                    PcrIndicator::where('id', $lineMap[$line->id])->update([
                        'parent_indicator_id' => $parentId,
                    ]);
                }
            }
        });

        $from = $target->type === 'ipcr'
            ? ($source->ratingPeriod?->label ?? 'the previous period')
            : ($source->schoolYear?->label ?? 'an earlier year');

        ActivityLog::record('PcrForm', $target->id, 'create', "Copied commitments from {$from}");

        return response()->json(['data' => 'copied', 'lines' => $copied]);
    }

    /** The owner's other IPCR for this school year — not somebody else's, and not another year. */
    private function isEarlierIpcr(PcrForm $source, PcrForm $target): bool
    {
        return $source->type === 'ipcr'
            && (int) $source->id !== (int) $target->id
            && (int) $source->user_id === (int) $target->user_id
            && (int) $source->school_year_id === (int) $target->school_year_id;
    }

    /**
     * A heading copied inside the same form follows its new parent. An IPCR
     * heading that answers a college MFO keeps that office link for the year.
     */
    private function copiedParentOutputId(?int $parentId, array $outputMap, PcrForm $target): ?int
    {
        if (! $parentId) {
            return null;
        }

        if (isset($outputMap[$parentId])) {
            return $outputMap[$parentId];
        }

        if ($target->type !== 'ipcr') {
            return null;
        }

        $parent = PcrOutput::with('form')->find($parentId);

        return $parent?->form
            && $parent->form->type === 'opcr'
            && (int) $parent->form->school_year_id === (int) $target->school_year_id
            ? $parentId
            : null;
    }

    /**
     * A line copied with its parent on the same form points at the copy. A line
     * that already answers an office target keeps that link — the target lasts
     * the school year, so the next review period still reports against it.
     */
    private function copiedParentIndicatorId(?int $parentId, array $lineMap, $officeParents): ?int
    {
        if (! $parentId) {
            return null;
        }

        if (isset($lineMap[$parentId])) {
            return $lineMap[$parentId];
        }

        return $officeParents->has($parentId) ? $parentId : null;
    }

    private function officeParentsFor(array $sourceLineIds, PcrForm $target)
    {
        if ($target->type !== 'ipcr' || $sourceLineIds === []) {
            return collect();
        }

        $parentIds = PcrIndicator::whereIn('id', $sourceLineIds)
            ->whereNotNull('parent_indicator_id')
            ->pluck('parent_indicator_id');

        return PcrIndicator::with('output.form')
            ->whereIn('id', $parentIds)
            ->get()
            ->filter(fn (PcrIndicator $parent) => $parent->output?->form?->type === 'opcr'
                && (int) $parent->output->form->school_year_id === (int) $target->school_year_id)
            ->keyBy('id');
    }

    private function publishedOpcrFor(PcrForm $form): ?PcrForm
    {
        $opcr = PcrForm::with(['outputs.indicators', 'outputs.parentOutput.parentOutput'])
            ->where('type', 'opcr')
            ->where('school_year_id', $form->school_year_id)
            ->when(
                ! app(WorkflowSettings::class)->opcrOnePerOrganization(),
                fn ($q) => $q->where('org_unit_id', $form->org_unit_id)
            )
            ->where(function ($query) {
                $query->whereIn('status', PcrWorkflow::OPCR_VISIBLE)
                    ->orWhere('status', 'returned');
            })
            ->first();

        return $opcr && PcrWorkflow::opcrIsVisible($opcr) ? $opcr : null;
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
                // An office target lasts the school year. Mid-year and end-year
                // IPCRs both link to it; the period on an old line does not hide it.
                return $output->indicators
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

        $handed = $assigned->map(fn ($assignment) => [
            'id'               => $assignment->indicator_id,
            'section'          => $assignment->indicator->output->section,
            'output_id'        => $assignment->indicator->output_id,
            'output_title'     => $this->outputPath($assignment->indicator->output),
            'description'      => $assignment->indicator->description,
            'rating_period_id' => $assignment->rating_period_id,
            'assigned'         => true,
            'assigned_by_name' => $assignment->assigned_by_name,
        ]);

        // Faculty and staff only answer what was handed to them. A head or VP
        // may also pick any other published college target.
        if ($form->picksAssignedTargetsOnly()) {
            return $handed->values();
        }

        return $handed->concat($fromOpcr)->unique('id')->values();
    }

    private function assignedTargetsFor(PcrForm $form): array
    {
        $assignments = $this->assignmentsFor($form);

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
                        ->orWhere('rating_period_id', $form->rating_period_id)
                        // An office target assigned in one period is still the
                        // target to answer in the other period of the same year.
                        ->orWhereHas(
                            'indicator.output.form',
                            fn ($owner) => $owner->where('type', 'opcr')
                        );
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

            $need = IndicatorProgressService::EVIDENCE_FILES;
            $done = $records->contains(
                fn ($record) => ! Html::isBlank($record->actual_accomplishment)
                    && (int) $record->attachments_count >= $need
            );

            if ($done) {
                continue;
            }

            $hasNarrative = $records->contains(
                fn ($record) => ! Html::isBlank($record->actual_accomplishment)
            );
            $files = (int) ($records->max(fn ($record) => (int) $record->attachments_count) ?? 0);

            $text = trim(Html::toText($line->description));
            $name = $text === '' ? 'A line' : '“' . mb_strimwidth($text, 0, 60, '…') . '”';
            $gaps[] = $this->writeUpGap($name, $hasNarrative, $files);
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

    /** What a line still owes before it can be submitted. Two files are required. */
    private function writeUpGap(string $name, bool $hasNarrative, int $files): string
    {
        $short = max(0, IndicatorProgressService::EVIDENCE_FILES - $files);

        if ($hasNarrative && $short > 1) {
            return "{$name} still needs two files.";
        }

        if ($hasNarrative && $short === 1) {
            return "{$name} still needs one more file.";
        }

        if (! $hasNarrative && $short === 0) {
            return "{$name} still needs a narrative.";
        }

        if (! $hasNarrative && $short === 1) {
            return "{$name} still needs a narrative and one more file.";
        }

        return "{$name} still needs a narrative and two files.";
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
            ->tap(function ($query) {
                foreach (app(WorkflowSettings::class)->ratingDimensionKeys() as $dimension) {
                    $query->whereNotNull($dimension);
                }
            })
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

        $passedHead = in_array($from, ['draft', 'returned', 'commitments_approved'], true)
            && in_array($to, ['dean_review', 'vp_review', 'qa_rating'], true);

        if ($passedHead && ! $form->head_reviewer_id) {
            $notes[] = 'Head review skipped — the ratee heads this office, or it has no separate head.';
        }

        $passedDean = in_array($from, ['draft', 'returned', 'commitments_approved', 'head_review'], true)
            && in_array($to, ['vp_review', 'qa_rating'], true);

        if ($passedDean && ! $form->dean_reviewer_id) {
            $notes[] = 'Dean review skipped — this unit has no dean, or the ratee holds that post.';
        }

        $passedVp = in_array($from, ['draft', 'returned', 'commitments_approved', 'head_review', 'dean_review', 'director_review'], true) && $to === 'qa_rating';

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
        // Human Resource reads every form, to monitor submission. The review
        // queue stays with whoever is named on the form, so an HR head still
        // only acts on the staff of the HR office.
        if ($user->role === 'hr' && ! $queueOnly) {
            return;
        }

        if (in_array($user->role, ['admin', 'qa', 'president'], true)) {
            if ($user->role === 'president' && $queueOnly) {
                $rules = app(WorkflowSettings::class);
                $extra = array_values(array_filter([
                    $rules->isRater('president') ? 'qa_rating' : null,
                    in_array('president', $rules->opcr('approver_roles'), true) ? 'qa_approval' : null,
                ]));

                $query->where(function ($q) use ($user, $extra) {
                    $q->where(function ($inner) use ($user) {
                        $inner->where('status', 'commitment_review')
                            ->where('commitment_approver_id', $user->id);
                    });

                    if ($extra !== []) {
                        $q->orWhereIn('status', $extra);
                    }
                });

                return;
            }

            if ($user->role === 'qa' && $queueOnly) {
                $rules    = app(WorkflowSettings::class);
                $waiting  = array_values(array_filter([
                    $rules->isRater('qa') ? 'qa_rating' : null,
                    in_array('qa', $rules->opcr('approver_roles'), true) ? 'qa_approval' : null,
                ]));

                // Staff IPCRs stay with the QA director. They are not sent upward.
                $query->where(function ($q) use ($user, $waiting) {
                    $q->whereIn('status', $waiting)
                        ->orWhere(fn ($inner) => $inner->namedFor($user->id, true))
                        ->orWhere(function ($inner) use ($user) {
                            $inner->where('status', 'commitment_review')
                                ->where('commitment_approver_id', $user->id);
                        });
                });
            }

            return;
        }

        if ($queueOnly && ! in_array($user->role, ['admin', 'qa', 'president'], true)) {
            $rules    = app(WorkflowSettings::class);
            $rates    = $rules->isRater($user->role);
            $approves = in_array($user->role, $rules->opcr('approver_roles'), true);

            if ($rates || $approves) {
                $waiting = array_values(array_filter([
                    $rates ? 'qa_rating' : null,
                    $approves ? 'qa_approval' : null,
                ]));

                $query->where(function ($q) use ($user, $waiting) {
                    $q->whereIn('status', $waiting)
                        ->orWhere(fn ($inner) => $inner->namedFor($user->id, true))
                        ->orWhere(function ($inner) use ($user) {
                            $inner->where('status', 'commitment_review')
                                ->where('commitment_approver_id', $user->id);
                        });
                });

                return;
            }
        }

        // Units this person oversees in either capacity — one person may hold
        // both a head and a VP post, so this is a union, not a role lookup.
        $unitIds = collect(OrgUnit::overseenIds((int) $user->id));
        $watched = OrgUnit::watchedIds((int) $user->id);

        if ($unitIds->isNotEmpty() || $watched !== [] || in_array($user->role, ['program_head', 'vp', 'hr'], true)) {
            if ($queueOnly) {
                // Authority is being named on the form, not holding a role.
                $query->where(function ($q) use ($user) {
                    $q->where(fn ($inner) => $inner->namedFor($user->id, true))
                        ->orWhere(function ($x) use ($user) {
                            $x->where('status', 'commitment_review')
                                ->where('commitment_approver_id', $user->id);
                        });
                })->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id);
                });

                return;
            }

            $query->where(function ($q) use ($unitIds, $user, $watched) {
                $q->whereIn('org_unit_id', $unitIds)
                    ->orWhere('user_id', $user->id)
                    ->orWhere(fn ($inner) => $inner->namedFor($user->id))
                    ->orWhere(fn ($inner) => $inner->accountableTo((int) $user->id));

                if ($watched !== []) {
                    $q->orWhereIn('org_unit_id', $watched)
                        ->orWhereIn('user_id', function ($inner) use ($watched) {
                            $inner->select('id')->from('users')->whereIn('org_unit_id', $watched);
                        });
                }
            });

            return;
        }

        $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere(fn ($inner) => $inner->accountableTo((int) $user->id));
        });
    }
}
