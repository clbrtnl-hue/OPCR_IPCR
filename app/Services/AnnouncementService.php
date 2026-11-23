<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\PcrForm;
use App\Models\PcrFormReviewer;
use App\Models\RatingPeriod;
use App\Models\User;
use App\Support\Html;
use Illuminate\Support\Facades\Auth;

/**
 * College broadcasts — manual (Setup → Announcements) and automatic when a
 * review period opens or is locked. Closing-soon notices stay on the daily
 * pms:remind job.
 */
class AnnouncementService
{
    /**
     * Fired when a period becomes open (status → open, or set as the active
     * review point). Everyone except admins gets the bell.
     */
    public function periodOpened(RatingPeriod $period): int
    {
        $period->loadMissing('schoolYear:id,label');

        $label = $period->label;
        $year  = $period->schoolYear?->label;
        $when  = $year ? "{$label} ({$year})" : $label;

        $body = "Please start or continue your IPCR for this period. Submit it before the closing date.";

        if ($period->closes_at) {
            $body .= ' Closes '.$period->closes_at->format('M j, Y').'.';
        }

        return $this->broadcast(
            title: "The review period is open — {$when}",
            body: $body,
            audiences: ['all'],
            roles: [],
            period: $period,
            link: '/my-forms?period='.$period->id,
            automatic: true,
        );
    }

    /** Fired when an administrator locks a period to view-only. */
    public function periodLocked(RatingPeriod $period): int
    {
        $period->loadMissing('schoolYear:id,label');

        $label = $period->label;
        $year  = $period->schoolYear?->label;
        $when  = $year ? "{$label} ({$year})" : $label;

        return $this->broadcast(
            title: "The review period is now locked — {$when}",
            body: 'Forms for this period are view-only. Ask an administrator if something still needs correcting.',
            audiences: ['all'],
            roles: [],
            period: $period,
            link: '/my-forms?period='.$period->id,
            automatic: true,
        );
    }

    /**
     * @param  list<string>  $audiences
     * @param  list<string>  $roles
     */
    public function broadcast(
        string $title,
        string $body,
        array $audiences,
        array $roles,
        ?RatingPeriod $period,
        ?string $link = null,
        bool $automatic = false,
    ): int {
        $audiences = array_values(array_unique($audiences));

        if (in_array('all', $audiences, true)) {
            $audiences = ['all'];
        }

        $userIds = [];

        foreach ($audiences as $audience) {
            $userIds = array_merge($userIds, $this->audienceIds($audience, $roles, $period));
        }

        $userIds = array_values(array_unique($userIds));
        $actorId = Auth::id();

        if ($actorId) {
            $userIds = array_values(array_filter(
                $userIds,
                fn ($id) => (int) $id !== (int) $actorId
            ));
        }

        if ($userIds === []) {
            return 0;
        }

        $plain = Html::toText($body);
        $href  = $link ?? ($period ? '/my-forms?period='.$period->id : '/notifications');

        Notification::sendMany($userIds, 'announcement', $title, $plain, null, $href);

        $audienceLabel = implode(' + ', $audiences);
        $prefix = $automatic ? 'Auto-announced' : 'Announced';

        ActivityLog::record(
            'Announcement',
            $period?->id,
            'create',
            "{$prefix} “{$title}” to {$audienceLabel} (".count($userIds).' people)',
            [
                'audiences'  => $audiences,
                'roles'      => $roles,
                'period'     => $period?->label,
                'sent'       => count($userIds),
                'automatic'  => $automatic,
            ]
        );

        return count($userIds);
    }

    /** @return list<int> */
    public function audienceIds(string $audience, array $roles, ?RatingPeriod $period): array
    {
        if ($audience === 'all') {
            return User::query()
                ->where('status', 'active')
                ->where('role', '!=', 'admin')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($audience === 'roles') {
            if ($roles === []) {
                return [];
            }

            return User::query()
                ->where('status', 'active')
                ->whereIn('role', $roles)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $forms = PcrForm::query()
            ->with(['owner:id,status,role', 'orgUnit', 'stageReviewers'])
            ->when($period, fn ($query) => $query->where('rating_period_id', $period->id))
            ->when(! $period, function ($query) {
                $activeId = RatingPeriod::query()->where('is_active', true)->value('id');

                if ($activeId) {
                    $query->where('rating_period_id', $activeId);
                }
            })
            ->whereNotIn('status', ['rated', 'final'])
            ->get();

        if ($audience === 'incomplete') {
            $ids = [];

            foreach ($forms as $form) {
                if (! in_array($form->status, ['draft', 'returned', 'commitments_approved'], true)) {
                    continue;
                }

                $owner = $form->owner;

                if ($owner && $owner->status === 'active' && $owner->role !== 'admin') {
                    $ids[] = (int) $owner->id;
                }
            }

            return array_values(array_unique($ids));
        }

        $ids = [];

        foreach ($forms as $form) {
            if (in_array($form->status, ['draft', 'returned', 'commitments_approved'], true)) {
                continue;
            }

            if ($form->status === 'commitment_review' && $form->commitment_approver_id) {
                $ids[] = (int) $form->commitment_approver_id;

                continue;
            }

            if (in_array($form->status, ['qa_rating', 'qa_approval'], true)) {
                $rules = app(WorkflowSettings::class);
                $roleList = $form->status === 'qa_rating'
                    ? $rules->raterRoles()
                    : $rules->opcr('approver_roles');

                foreach (User::query()->whereIn('role', $roleList)->where('status', 'active')->pluck('id') as $id) {
                    $ids[] = (int) $id;
                }

                continue;
            }

            if (PcrWorkflow::isReviewDestination($form->status)) {
                $named = PcrFormReviewer::query()
                    ->where('form_id', $form->id)
                    ->where('stage', $form->status)
                    ->value('reviewer_id');

                if ($named) {
                    $ids[] = (int) $named;
                } elseif ($id = PcrWorkflow::reviewerId($form, $form->status)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
