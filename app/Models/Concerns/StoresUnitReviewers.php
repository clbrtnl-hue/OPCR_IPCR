<?php

namespace App\Models\Concerns;

use App\Models\OrgUnitReviewer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;

/**
 * Head, dean, and VP live in org_unit_reviewers with every other slot.
 * The old names still read and write that one row, so callers keep working.
 */
trait StoresUnitReviewers
{
    /** @var array<string, int|null> */
    public array $pendingReviewerSlots = [];

    private ?Collection $reviewerRowCache = null;

    public static function bootStoresUnitReviewers(): void
    {
        static::saved(function (self $unit) {
            $unit->flushReviewerSlots();
        });
    }

    protected function headUserId(): Attribute
    {
        return $this->unitSlotAttribute('head_user_id');
    }

    protected function deanUserId(): Attribute
    {
        return $this->unitSlotAttribute('dean_user_id');
    }

    protected function vpUserId(): Attribute
    {
        return $this->unitSlotAttribute('vp_user_id');
    }

    private function unitSlotAttribute(string $slot)
    {
        return Attribute::make(
            get: fn () => $this->reviewerIdForSlot($slot),
            set: function ($value) use ($slot) {
                $this->pendingReviewerSlots[$slot] = $value ? (int) $value : null;

                return [];
            },
        )->withoutObjectCaching();
    }

    public function flushReviewerSlots(): void
    {
        if ($this->pendingReviewerSlots === [] || ! $this->id) {
            return;
        }

        foreach ($this->pendingReviewerSlots as $slot => $userId) {
            OrgUnitReviewer::updateOrCreate(
                ['org_unit_id' => $this->id, 'slot' => $slot],
                ['user_id' => $userId]
            );
        }

        $this->pendingReviewerSlots = [];
        $this->reviewerRowCache = null;
        $this->unsetRelation('reviewers');
    }

    private function reviewerRows(): Collection
    {
        if ($this->relationLoaded('reviewers')) {
            return $this->reviewers;
        }

        if ($this->reviewerRowCache !== null) {
            return $this->reviewerRowCache;
        }

        if (! $this->exists) {
            return $this->reviewerRowCache = collect();
        }

        return $this->reviewerRowCache = $this->reviewers()->get();
    }
}
