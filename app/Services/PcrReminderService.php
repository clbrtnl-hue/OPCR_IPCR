<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\RatingPeriod;
use App\Models\User;
use App\Support\Html;
use Illuminate\Support\Carbon;

/**
 * Deadline reminders. Each one is sent once: seven days out, on the target
 * date, and again when the date has passed. A closing period is one notice
 * to whoever still has a draft or a form sitting in their queue. A form that
 * has been rated is left alone.
 */
class PcrReminderService
{
    private const QUIET = ['rated', 'final'];

    public function send(): array
    {
        return [
            'deadlines' => $this->deadlines(),
            'periods'   => $this->periods(),
        ];
    }

    private function deadlines(): int
    {
        $sent = 0;
        $office = [];

        $lines = PcrIndicator::inForce()
            ->whereNotNull('target_date')
            ->where(function ($query) {
                $query->whereNull('progress_status')
                    ->orWhereNotIn('progress_status', ['completed', 'deferred']);
            })
            ->whereDoesntHave('children')
            ->whereDoesntHave('assignments')
            ->with(['output.form.owner', 'output.form.orgUnit.head'])
            ->get();

        foreach ($lines as $line) {
            $form = $line->output?->form;
            $ownerId = $this->accountable($form);

            if (! $form || ! $ownerId || in_array($form->status, self::QUIET, true)) {
                continue;
            }

            $delay = $line->delay;
            $what = mb_substr(Html::toText($line->description), 0, 160);
            $lineLink = "/forms/{$form->id}?line={$line->id}";

            if ($delay['state'] === 'due_soon' && (int) $delay['days'] === 0) {
                $sent += (int) Notification::remind(
                    $ownerId,
                    'due_today',
                    'A commitment is due today',
                    $what,
                    $form->id,
                    $lineLink . '&when=today'
                );

                continue;
            }

            if ($delay['state'] === 'due_soon') {
                $sent += (int) Notification::remind(
                    $ownerId,
                    'due_soon',
                    'A commitment is due within 7 days',
                    $what,
                    $form->id,
                    $lineLink . '&when=soon'
                );

                continue;
            }

            if ($delay['state'] !== 'overdue') {
                continue;
            }

            $raised = Notification::remind(
                $ownerId,
                'overdue',
                'A commitment is overdue',
                $what,
                $form->id,
                $lineLink . '&when=overdue'
            );

            if (! $raised) {
                continue;
            }

            $sent++;
            $head = $form->orgUnit?->head;

            if ($head && $head->status === 'active' && $head->role !== 'president' && (int) $head->id !== $ownerId) {
                $office[$head->id] = ($office[$head->id] ?? 0) + 1;
            }
        }

        foreach ($office as $headId => $count) {
            Notification::remind(
                $headId,
                'overdue',
                $count === 1
                    ? 'A commitment in your office is overdue'
                    : "{$count} commitments in your office are overdue",
                'They are past their target date.',
                null,
                '/reports?tab=commitments&late=1'
            );
        }

        return $sent;
    }

    private function periods(): int
    {
        $sent = 0;
        $today = now()->startOfDay();

        $periods = RatingPeriod::query()
            ->whereNotNull('closes_at')
            ->whereIn('status', ['open', 'upcoming'])
            ->get()
            ->filter(fn (RatingPeriod $period) => $this->isClosing($period, $today));

        foreach ($periods as $period) {
            $left = (int) $today->diffInDays($period->closes_at->copy()->startOfDay(), false);
            $when = $left <= 0
                ? 'today'
                : 'in ' . $left . ' ' . ($left === 1 ? 'day' : 'days');

            $forms = PcrForm::query()
                ->forActiveAccounts()
                ->with(['owner', 'orgUnit'])
                ->where('rating_period_id', $period->id)
                ->whereNotIn('status', self::QUIET)
                ->get();

            $queue = [];

            foreach ($forms as $form) {
                if (in_array($form->status, ['draft', 'returned'], true)) {
                    $ownerId = $this->accountable($form);

                    if ($ownerId) {
                        $sent += (int) Notification::remind(
                            $ownerId,
                            'period',
                            "The rating period closes {$when}",
                            "Your " . strtoupper($form->type) . " for {$period->label} is still " . ($form->status === 'returned' ? 'returned' : 'a draft') . '.',
                            $form->id,
                            "/forms/{$form->id}?period={$period->id}"
                        );
                    }

                    continue;
                }

                foreach ($this->reviewers($form) as $reviewerId) {
                    $queue[$reviewerId][$form->id] = $form->id;
                }
            }

            foreach ($queue as $reviewerId => $formIds) {
                $formIds = array_values($formIds);
                $count = count($formIds);
                $only = $count === 1 ? $forms->firstWhere('id', $formIds[0]) : null;

                $sent += (int) Notification::remind(
                    $reviewerId,
                    'period',
                    "The rating period closes {$when}",
                    $count === 1
                        ? "1 form is still in your review queue for {$period->label}."
                        : "{$count} forms are still in your review queue for {$period->label}.",
                    $only?->id,
                    $only
                        ? $only->queueLink()
                        : "/review-queue?period={$period->id}"
                );
            }
        }

        return $sent;
    }

    private function isClosing(RatingPeriod $period, Carbon $today): bool
    {
        $closes = $period->closes_at?->copy()->startOfDay();

        if (! $closes || $closes->lt($today)) {
            return false;
        }

        if ($period->opens_at && $period->opens_at->copy()->startOfDay()->gt($today)) {
            return false;
        }

        return $today->diffInDays($closes, false) <= 7;
    }

    /** The person who has to act. The president is not pinged for each form. */
    private function accountable(?PcrForm $form): ?int
    {
        if (! $form) {
            return null;
        }

        $owner = $form->owner;

        // A deactivated ratee cannot finish the form, so it is not handed to
        // the office head as if it were still their draft.
        if ($form->type === 'ipcr' && (! $owner || $owner->status !== 'active')) {
            return null;
        }

        if ($owner && $owner->status === 'active' && $owner->role !== 'president') {
            return (int) $owner->id;
        }

        $head = $form->orgUnit?->head;

        if ($head && $head->status === 'active' && $head->role !== 'president') {
            return (int) $head->id;
        }

        return null;
    }

    /** @return array<int, int> */
    private function reviewers(PcrForm $form): array
    {
        if (in_array($form->status, ['qa_rating', 'qa_approval'], true)) {
            $rules = app(WorkflowSettings::class);
            $roles = $form->status === 'qa_rating'
                ? $rules->raterRoles()
                : $rules->opcr('approver_roles');

            return User::query()
                ->whereIn('role', $roles)
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $ids = $form->stageReviewers()
            ->where('stage', $form->status)
            ->pluck('reviewer_id')
            ->filter()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
