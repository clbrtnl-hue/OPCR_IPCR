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
 * has been rated but not closed is one notice to QA, linking straight to it.
 */
class PcrReminderService
{
    private const QUIET = ['rated', 'final'];

    public function send(): array
    {
        return [
            'deadlines' => $this->deadlines(),
            'periods'   => $this->periods(),
            'unclosed'  => $this->unclosed(),
        ];
    }

    private function deadlines(): int
    {
        $sent = 0;
        $office = [];

        $lines = PcrIndicator::query()
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
                '/reports?late=' . now()->toDateString()
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
                    $queue[$reviewerId] = ($queue[$reviewerId] ?? 0) + 1;
                }
            }

            foreach ($queue as $reviewerId => $count) {
                $sent += (int) Notification::remind(
                    $reviewerId,
                    'period',
                    "The rating period closes {$when}",
                    $count === 1
                        ? "1 form is still in your review queue for {$period->label}."
                        : "{$count} forms are still in your review queue for {$period->label}.",
                    null,
                    "/review-queue?period={$period->id}"
                );
            }
        }

        return $sent;
    }

    /** Rated, but not yet closed. QA is the one who closes it. */
    private function unclosed(): int
    {
        $qaIds = User::query()
            ->where('role', 'qa')
            ->where('status', 'active')
            ->pluck('id');

        if ($qaIds->isEmpty()) {
            return 0;
        }

        $forms = PcrForm::query()
            ->with(['owner:id,name', 'orgUnit:id,name'])
            ->where('status', 'rated')
            ->get();

        $sent = 0;

        foreach ($forms as $form) {
            $who = $form->owner?->name ?? $form->orgUnit?->name ?? 'this office';
            $label = strtoupper($form->type);

            foreach ($qaIds as $qaId) {
                $sent += (int) Notification::remind(
                    (int) $qaId,
                    'unclosed',
                    'A rated form still needs to be closed',
                    "{$label} for {$who} has been rated. Open it and close the form.",
                    $form->id,
                    "/forms/{$form->id}"
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
        $ids = match ($form->status) {
            'head_review' => [$form->head_reviewer_id],
            'vp_review'   => [$form->vp_reviewer_id],
            'qa_rating', 'qa_approval' => User::query()
                ->where('role', 'qa')
                ->where('status', 'active')
                ->pluck('id')
                ->all(),
            default => [],
        };

        return array_values(array_filter(array_map('intval', $ids)));
    }
}
