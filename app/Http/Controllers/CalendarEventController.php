<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AttendanceCategory;
use App\Models\AttendanceDay;
use App\Models\AttendanceRate;
use App\Models\CalendarEvent;
use App\Models\CalendarEventMark;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\AttendanceSync;
use App\Support\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $user = $request->user();
        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        $events = CalendarEvent::query()
            ->visibleTo($user)
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->with([
                'owner:id,name',
                'viewers:id,name',
                'category:id,name,slug,source,system',
                'marks' => fn ($query) => $query
                    ->where('status', 'present')
                    ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                    ->with('user:id,name,image'),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return response()->json(
            $events->map(fn (CalendarEvent $event) => $this->payload($event, $user))->values()
        );
    }

    public function show(Request $request, $id)
    {
        $event = $this->visible($request, $id);
        $event->load(['owner:id,name', 'viewers:id,name', 'category', 'marks.user:id,name,image']);

        return response()->json(['event' => $this->payload($event, $request->user(), true)]);
    }

    public function store(Request $request)
    {
        $input = $this->validated($request);
        $event = new CalendarEvent($input['attributes']);
        $event->created_by = $request->user()->id;
        $event->save();
        $this->syncViewers($event, $input['viewer_ids']);
        $this->notifyAudience($event);

        ActivityLog::record('CalendarEvent', $event->id, 'create', "Scheduled {$event->title}");

        $event->load(['owner:id,name', 'viewers:id,name', 'category', 'marks.user:id,name,image']);

        return response()->json(['event' => $this->payload($event, $request->user(), true)], 201);
    }

    public function update(Request $request, $id)
    {
        $event = $this->own($request, $id);
        $before = $this->markRows($event);
        $already = $this->audienceIds($event);
        $input = $this->validated($request);
        $event->fill($input['attributes'])->save();
        $this->syncViewers($event, $input['viewer_ids']);
        $event->load('viewers:id,name');
        $this->notifyAudience($event, $already);
        $this->pruneMarks($event);
        $this->resync($request->user()->id, array_merge($before, $this->markRows($event)));

        ActivityLog::record('CalendarEvent', $event->id, 'update', "Updated {$event->title}");

        $event->load(['owner:id,name', 'viewers:id,name', 'category', 'marks.user:id,name,image']);

        return response()->json(['event' => $this->payload($event, $request->user(), true)]);
    }

    public function destroy(Request $request, $id)
    {
        $event = $this->own($request, $id);
        $before = $this->markRows($event);
        $title = $event->title;
        $event->delete();
        $this->resync($request->user()->id, $before);

        ActivityLog::record('CalendarEvent', (int) $id, 'delete', "Removed {$title}");

        return response()->json(['data' => 'deleted']);
    }

    public function mark(Request $request, $id)
    {
        $event = $this->own($request, $id);

        if (in_array($request->user()->role, CalendarEvent::SCHEDULE_ONLY, true)) {
            return response()->json(['message' => 'Attendance is not on this account.'], 403);
        }

        if (! $event->counts()) {
            return response()->json([
                'message' => 'Pick a category and a review period before marking attendance.',
            ], 422);
        }

        $data = $request->validate([
            'user_id' => 'required|integer',
            'day'     => 'required|date',
            'status'  => 'nullable|in:' . implode(',', CalendarEventMark::STATUSES),
        ]);

        $period = RatingPeriod::query()->findOrFail($event->rating_period_id);

        if ($period->isLockedFor($request->user())) {
            return response()->json(['message' => 'This rating period is locked.'], 423);
        }

        $day = Carbon::parse($data['day'])->toDateString();

        if (! in_array($day, $event->dates(), true)) {
            return response()->json(['message' => 'That day is outside this event.'], 422);
        }

        $rosterIds = collect($this->roster($event))->pluck('id')->map(fn ($personId) => (int) $personId)->all();

        if (! in_array((int) $data['user_id'], $rosterIds, true)) {
            return response()->json(['message' => 'That person is not on this event.'], 422);
        }

        $where = [
            'event_id' => $event->id,
            'user_id'  => (int) $data['user_id'],
            'day'      => $day,
        ];

        if (empty($data['status'])) {
            CalendarEventMark::query()->where($where)->delete();
        } else {
            CalendarEventMark::updateOrCreate($where, [
                'status'    => $data['status'],
                'marked_by' => $request->user()->id,
            ]);
        }

        AttendanceSync::dayFromActivities((int) $request->user()->id, (int) $data['user_id'], (int) $event->rating_period_id, $day);
        AttendanceRate::remember((int) $data['user_id'], (int) $event->rating_period_id);

        $event->load(['owner:id,name', 'viewers:id,name', 'category', 'marks.user:id,name,image']);

        return response()->json(['event' => $this->payload($event, $request->user(), true)]);
    }

    /**
     * @return array{attributes: array<string, mixed>, viewer_ids: array<int, int>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'             => 'required|string|max:200',
            'notes'             => 'nullable|string|max:2000',
            'starts_at'         => 'required|date',
            'ends_at'           => 'required|date',
            'all_day'           => 'required|boolean',
            'visibility'        => 'required|in:' . implode(',', CalendarEvent::VISIBILITY),
            'user_ids'          => 'nullable|array',
            'user_ids.*'        => 'integer',
            'audience_role'     => 'nullable|string|max:20',
            'category_id'       => 'nullable|integer',
            'rating_period_id'  => 'nullable|integer',
        ]);

        $college = in_array($request->user()->role, CalendarEvent::COLLEGE_WIDE, true);
        $scheduleOnly = in_array($request->user()->role, CalendarEvent::SCHEDULE_ONLY, true);
        $audienceRole = null;

        $start = Carbon::parse($data['starts_at']);
        $end   = Carbon::parse($data['ends_at']);

        if ($data['all_day']) {
            $start = $start->copy()->startOfDay();
            $end   = $end->copy()->endOfDay();
        }

        if ($end->lt($start)) {
            abort(response()->json([
                'message' => 'The end has to be on or after the start.',
                'errors'  => ['ends_at' => ['The end has to be on or after the start.']],
            ], 422));
        }

        $this->insideSchoolYear($start, $end);

        if ($request->user()->role === 'employee' && $data['visibility'] !== 'only_me') {
            abort(response()->json([
                'message' => 'Your events stay on your calendar.',
                'errors'  => ['visibility' => ['Your events stay on your calendar.']],
            ], 422));
        }

        $viewerIds = [];

        if ($data['visibility'] === 'role') {
            if (! $college) {
                abort(response()->json([
                    'message' => 'By role is for the president and administrators.',
                    'errors'  => ['visibility' => ['By role is for the president and administrators.']],
                ], 422));
            }

            $audienceRole = $data['audience_role'] ?? null;

            if (! in_array($audienceRole, CalendarEvent::AUDIENCE_ROLES, true)) {
                abort(response()->json([
                    'message' => 'Pick a role.',
                    'errors'  => ['audience_role' => ['Pick a role.']],
                ], 422));
            }
        }

        if ($data['visibility'] === 'everyone' && ! $college && OrgUnit::subordinates((int) $request->user()->id) === null) {
            abort(response()->json([
                'message' => 'Everyone covers the people in the offices you head.',
                'errors'  => ['visibility' => ['Everyone covers the people in the offices you head.']],
            ], 422));
        }

        if ($data['visibility'] === 'selected') {
            $viewerIds = collect($data['user_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->reject(fn (int $id) => $id === (int) $request->user()->id)
                ->values()
                ->all();

            if ($viewerIds === []) {
                abort(response()->json([
                    'message' => 'Choose at least one person.',
                    'errors'  => ['user_ids' => ['Choose at least one person.']],
                ], 422));
            }

            $found = User::query()
                ->where('status', 'active')
                ->whereIn('id', $viewerIds);
            $offices = OrgUnit::overseenIds((int) $request->user()->id);

            if ($college) {
                $message = 'Choose people from this college.';
            } elseif ($offices !== []) {
                $found->whereIn('org_unit_id', $offices)
                    ->whereNotIn('role', ['admin', 'vp', 'president']);
                $message = 'Choose people from the offices you head.';
            } else {
                $message = 'Choose people from this organization.';
            }

            if ($found->count() !== count($viewerIds)) {
                abort(response()->json([
                    'message' => $message,
                    'errors'  => ['user_ids' => [$message]],
                ], 422));
            }
        }

        $categoryId = null;
        $periodId = null;

        if (! $scheduleOnly && $data['visibility'] !== 'only_me' && ! empty($data['category_id'])) {
            if (! AttendanceCategory::linkableBy($request->user())) {
                abort(response()->json([
                    'message' => 'Only a head can attach an attendance category.',
                ], 403));
            }

            $category = AttendanceCategory::query()->find($data['category_id']);

            if (! $category || $category->source !== 'event') {
                abort(response()->json([
                    'message' => 'Choose an activity category. Everyday attendance stays on the daily sheet.',
                    'errors'  => ['category_id' => ['Choose an activity category.']],
                ], 422));
            }

            if (empty($data['rating_period_id'])) {
                abort(response()->json([
                    'message' => 'Pick the review period this activity belongs to.',
                    'errors'  => ['rating_period_id' => ['Pick the review period this activity belongs to.']],
                ], 422));
            }

            $period = RatingPeriod::query()->with('schoolYear')->find($data['rating_period_id']);
            $orgId = app(CurrentOrganization::class)->id();

            if (! $period || ($orgId && (int) $period->schoolYear?->organization_id !== (int) $orgId)) {
                abort(response()->json([
                    'message' => 'Choose a review period from this organization.',
                    'errors'  => ['rating_period_id' => ['Choose a review period from this organization.']],
                ], 422));
            }

            $span = AttendanceDay::periodSpan($period);
            $first = $start->copy()->startOfDay();
            $last = $end->copy()->startOfDay();

            if (! $span || $first->lt($span[0]) || $last->gt($span[1])) {
                abort(response()->json([
                    'message' => 'The event has to fall inside the review period.',
                    'errors'  => ['starts_at' => ['The event has to fall inside the review period.']],
                ], 422));
            }

            $categoryId = $category->id;
            $periodId = $period->id;
        }

        return [
            'attributes' => [
                'title'            => $data['title'],
                'notes'            => isset($data['notes']) && $data['notes'] !== '' ? $data['notes'] : null,
                'starts_at'        => $start,
                'ends_at'          => $end,
                'all_day'          => (bool) $data['all_day'],
                'visibility'       => $data['visibility'],
                'audience_role'    => $audienceRole,
                'category_id'      => $categoryId,
                'rating_period_id' => $periodId,
            ],
            'viewer_ids' => $viewerIds,
        ];
    }

    private function insideSchoolYear(Carbon $start, Carbon $end): void
    {
        $years = SchoolYear::query()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->get(['start_date', 'end_date']);

        if ($years->isEmpty()) {
            return;
        }

        $covers = function (Carbon $day) use ($years): bool {
            return $years->contains(function (SchoolYear $year) use ($day) {
                return $day->gte($year->start_date->copy()->startOfDay())
                    && $day->lte($year->end_date->copy()->startOfDay());
            });
        };

        $errors = [];
        $message = 'Pick a date inside a school year.';

        if (! $covers($start->copy()->startOfDay())) {
            $errors['starts_at'] = [$message];
        }

        if (! $covers($end->copy()->startOfDay())) {
            $errors['ends_at'] = [$message];
        }

        if ($errors !== []) {
            abort(response()->json([
                'message' => $message,
                'errors'  => $errors,
            ], 422));
        }
    }

    /**
     * @param  array<int, int>  $viewerIds
     */
    private function syncViewers(CalendarEvent $event, array $viewerIds): void
    {
        if ($event->visibility !== 'selected') {
            $event->viewers()->detach();

            return;
        }

        $event->viewers()->sync(collect($viewerIds)->mapWithKeys(fn (int $id) => [
            $id => ['organization_id' => $event->organization_id],
        ])->all());
    }

    /**
     * People who should see this event, besides the person who created it.
     *
     * @return array<int, int>
     */
    private function audienceIds(CalendarEvent $event): array
    {
        $creator = User::query()->find($event->created_by);

        if (! $creator) {
            return [];
        }

        if ($event->visibility === 'selected') {
            return $event->viewers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        }

        if ($event->visibility === 'role' && $event->audience_role) {
            return User::query()
                ->where('status', 'active')
                ->where('role', $event->audience_role)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($event->visibility !== 'everyone') {
            return [];
        }

        if (in_array($creator->role, CalendarEvent::COLLEGE_WIDE, true)) {
            return User::query()
                ->where('status', 'active')
                ->where('id', '!=', $creator->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $people = OrgUnit::subordinates((int) $creator->id);

        return $people
            ? $people->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    /**
     * @param  array<int, int>  $exceptIds
     */
    private function notifyAudience(CalendarEvent $event, array $exceptIds = []): void
    {
        $event->loadMissing('owner:id,name');
        $ids = array_values(array_diff(
            $this->audienceIds($event),
            $exceptIds,
            [(int) $event->created_by]
        ));

        if ($ids === []) {
            return;
        }

        $when = $event->all_day
            ? $event->starts_at->timezone('Asia/Manila')->format('M j, Y')
            : $event->starts_at->timezone('Asia/Manila')->format('M j, Y g:i A');

        Notification::sendMany(
            $ids,
            'calendar',
            ($event->owner?->name ?? 'Someone') . " added {$event->title} to your calendar",
            $when,
            null,
            '/attendance?tab=calendar',
        );
    }

    private function pruneMarks(CalendarEvent $event): void
    {
        if (! $event->counts()) {
            $event->marks()->delete();

            return;
        }

        $event->marks()->whereNotIn('day', $event->dates())->delete();

        if ($event->visibility === 'selected') {
            $ids = $event->viewers()->pluck('users.id');
            $event->marks()->whereNotIn('user_id', $ids)->delete();
        }
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: int}>
     */
    private function markRows(CalendarEvent $event): array
    {
        if (! $event->rating_period_id) {
            return [];
        }

        return $event->marks()->get(['user_id', 'day'])->map(fn (CalendarEventMark $mark) => [
            (int) $mark->user_id,
            $mark->day->toDateString(),
            (int) $event->rating_period_id,
        ])->all();
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}>  $rows
     */
    private function resync(int $actorId, array $rows): void
    {
        $seen = [];

        foreach ($rows as [$userId, $day, $periodId]) {
            if (! $periodId || $day === '') {
                continue;
            }

            $key = $userId . '|' . $day . '|' . $periodId;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            AttendanceSync::dayFromActivities($actorId, $userId, $periodId, $day);
            AttendanceRate::remember($userId, $periodId);
        }
    }

    private function own(Request $request, $id): CalendarEvent
    {
        $event = CalendarEvent::query()->findOrFail($id);

        if ((int) $event->created_by !== (int) $request->user()->id) {
            abort(response()->json([
                'message' => 'You can only change events you created.',
            ], 403));
        }

        return $event;
    }

    private function visible(Request $request, $id): CalendarEvent
    {
        $event = CalendarEvent::query()->visibleTo($request->user())->find($id);

        if (! $event) {
            abort(response()->json(['message' => 'That event is not on your calendar.'], 404));
        }

        return $event;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function roster(CalendarEvent $event): array
    {
        if ($event->visibility === 'selected') {
            $event->loadMissing('viewers:id,name');

            return $event->viewers->map(fn (User $viewer) => [
                'id'   => $viewer->id,
                'name' => $viewer->name,
            ])->values()->all();
        }

        if ($event->visibility === 'everyone') {
            $people = OrgUnit::subordinates((int) $event->created_by);

            if (! $people) {
                return [];
            }

            return $people->orderBy('name')->get(['id', 'name'])->map(fn (User $person) => [
                'id'   => $person->id,
                'name' => $person->name,
            ])->all();
        }

        return [];
    }

    private function payload(CalendarEvent $event, User $user, bool $full = false): array
    {
        $payload = [
            'id'               => $event->id,
            'title'            => $event->title,
            'notes'            => $event->notes,
            'starts_at'        => $event->starts_at->format('Y-m-d H:i:s'),
            'ends_at'          => $event->ends_at->format('Y-m-d H:i:s'),
            'all_day'          => (bool) $event->all_day,
            'visibility'       => $event->visibility,
            'audience_role'    => $event->audience_role,
            'category_id'      => $event->category_id,
            'rating_period_id' => $event->rating_period_id,
            'category'         => $event->category
                ? ['id' => $event->category->id, 'name' => $event->category->name]
                : null,
            'mine'             => (int) $event->created_by === (int) $user->id,
            'owner'            => $event->owner
                ? ['id' => $event->owner->id, 'name' => $event->owner->name]
                : null,
            'viewers'          => $event->visibility === 'selected'
                ? $event->viewers->map(fn (User $viewer) => [
                    'id'   => $viewer->id,
                    'name' => $viewer->name,
                ])->values()
                : [],
            'present'          => $event->relationLoaded('marks')
                ? $event->marks
                    ->filter(fn (CalendarEventMark $mark) => $mark->status === 'present')
                    ->map(fn (CalendarEventMark $mark) => [
                        'user_id' => $mark->user_id,
                        'name'    => $mark->user?->name,
                        'image'   => $mark->user?->image,
                        'day'     => $mark->day->toDateString(),
                    ])->values()
                : [],
        ];

        if ($full) {
            $payload['roster'] = $this->roster($event);
            $payload['marks'] = $event->marks->map(fn (CalendarEventMark $mark) => [
                'user_id' => $mark->user_id,
                'day'     => $mark->day->toDateString(),
                'status'  => $mark->status,
            ])->values();
        }

        return $payload;
    }
}
