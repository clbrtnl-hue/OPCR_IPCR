<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUnitReviewers;
use Illuminate\Database\Eloquent\Model;

class OrgUnit extends Model
{
    use BelongsToOrganization;
    use StoresUnitReviewers;

    /** Shown on the chart only. Slots named chart_display* are not review steps. */
    public const CHART_LIMIT = 10;

    protected $table = 'org_units';

    protected $guarded = [];

    protected $appends = ['head_user_id', 'dean_user_id', 'vp_user_id'];

    public function parent()
    {
        return $this->belongsTo(OrgUnit::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(OrgUnit::class, 'parent_id');
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function dean()
    {
        return $this->belongsTo(User::class, 'dean_user_id');
    }

    public function vp()
    {
        return $this->belongsTo(User::class, 'vp_user_id');
    }

    public function members()
    {
        return $this->hasMany(User::class, 'org_unit_id');
    }

    public function reviewers()
    {
        return $this->hasMany(OrgUnitReviewer::class, 'org_unit_id');
    }

    /** People who may read this unit's forms. They are not a review step. */
    public function viewers()
    {
        return $this->hasMany(OrgUnitViewer::class, 'org_unit_id');
    }

    /**
     * Units this person was allowed to open, plus every unit under those.
     * Review slots are not included here.
     */
    public static function watchedIds(int $userId): array
    {
        $roots = OrgUnitViewer::query()
            ->where('user_id', $userId)
            ->pluck('org_unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return static::subtreeIds($roots);
    }

    /** The person named for a review step. Every slot, including head, dean, and VP, is one reviewer row. */
    public function reviewerIdForSlot(string $slot): ?int
    {
        if (array_key_exists($slot, $this->pendingReviewerSlots)) {
            return $this->pendingReviewerSlots[$slot];
        }

        $named = $this->reviewerRows()->firstWhere('slot', $slot);

        return $named?->user_id ? (int) $named->user_id : null;
    }

    /**
     * Who reviews a form in this unit. An empty Dean, VP, or extra step uses
     * the parent. Head does not: each unit's head is its own, or the step is
     * skipped. A deactivated account is an empty slot, so the chain keeps
     * walking instead of stopping on somebody who cannot sign in.
     */
    public function reviewerIdForSlotInChain(string $slot): ?int
    {
        $named = $this->activeReviewerIdForSlot($slot);

        if ($named || $slot === 'head_user_id' || $slot === '') {
            return $named;
        }

        $seen    = [(int) $this->id => true];
        $current = $this;

        while ($current->parent_id) {
            $parentId = (int) $current->parent_id;

            if (isset($seen[$parentId])) {
                break;
            }

            $seen[$parentId] = true;
            $current         = $current->relationLoaded('parent') && (int) $current->parent?->id === $parentId
                ? $current->parent
                : static::query()->find($parentId);

            if (! $current) {
                break;
            }

            $named = $current->activeReviewerIdForSlot($slot);

            if ($named) {
                return $named;
            }
        }

        return null;
    }

    /** The person named in this slot, when that account can still sign in. */
    private function activeReviewerIdForSlot(string $slot): ?int
    {
        $named = $this->reviewerIdForSlot($slot);

        if (! $named) {
            return null;
        }

        $active = User::query()->whereKey($named)->where('status', 'active')->exists();

        return $active ? $named : null;
    }

    /**
     * Offices where this person is named on any review slot.
     */
    public function scopeWhereLedBy($query, int $userId)
    {
        return $query->whereHas('reviewers', function ($reviewers) use ($userId) {
            $reviewers->where('user_id', $userId)->where('slot', 'not like', 'chart_display%');
        });
    }

    /**
     * The offices this person heads or reviews, plus every unit under those
     * offices, so a dean sees the departments and a VP sees the colleges.
     */
    public static function overseenIds(int $userId): array
    {
        $roots = static::query()
            ->whereLedBy($userId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return static::subtreeIds($roots);
    }

    /**
     * Heads of this unit and of the offices above it. A person in a program
     * is a subordinate of the dean who leads the college.
     *
     * @return array<int, int>
     */
    public static function leaderIdsForMember(?int $orgUnitId): array
    {
        if (! $orgUnitId) {
            return [];
        }

        $ancestors = [];
        $id = $orgUnitId;
        $guard = 0;

        while ($id && $guard < 30) {
            $ancestors[] = (int) $id;
            $id = static::query()->whereKey($id)->value('parent_id');
            $guard++;
        }

        return OrgUnitReviewer::query()
            ->whereIn('org_unit_id', $ancestors)
            ->where('slot', 'not like', 'chart_display%')
            ->pluck('user_id')
            ->map(fn ($userId) => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Active people in the offices this person leads, not the person themselves.
     */
    public static function subordinates(int $userId): ?\Illuminate\Database\Eloquent\Builder
    {
        $offices = static::overseenIds($userId);

        if ($offices === []) {
            return null;
        }

        return User::query()
            ->where('status', 'active')
            ->whereIn('org_unit_id', $offices)
            ->where('id', '!=', $userId)
            ->whereNotIn('role', ['admin', 'vp', 'president']);
    }

    /**
     * People in a unit plus the people in every unit under it. Membership
     * itself stays on the unit each person belongs to.
     *
     * @param  iterable<int, self>  $units
     * @return array<int, int>
     */
    public static function rolledMemberCounts(iterable $units): array
    {
        $childrenOf = [];
        $direct     = [];

        foreach ($units as $unit) {
            $id          = (int) $unit->id;
            $direct[$id] = (int) $unit->members_count;

            if ($unit->parent_id) {
                $childrenOf[(int) $unit->parent_id][] = $id;
            }
        }

        $rolled = [];

        $walk = function (int $id, array $stack) use (&$walk, &$rolled, $childrenOf, $direct): int {
            if (isset($rolled[$id])) {
                return $rolled[$id];
            }

            if (isset($stack[$id])) {
                return $direct[$id] ?? 0;
            }

            $stack[$id] = true;
            $total      = $direct[$id] ?? 0;

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $total += $walk($childId, $stack);
            }

            return $rolled[$id] = $total;
        };

        foreach (array_keys($direct) as $id) {
            $walk($id, []);
        }

        return $rolled;
    }

    /** A root office plus every unit under it. */
    public static function subtreeIds(array $rootIds): array
    {
        $units = static::query()->get(['id', 'parent_id']);

        $childrenOf = [];

        foreach ($units as $unit) {
            if ($unit->parent_id) {
                $childrenOf[(int) $unit->parent_id][] = (int) $unit->id;
            }
        }

        $stack = array_map('intval', $rootIds);
        $seen  = [];

        while ($stack) {
            $id = array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_keys($seen);
    }
}
