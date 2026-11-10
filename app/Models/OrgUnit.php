<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OrgUnit extends Model
{
    use BelongsToOrganization;

    protected $table = 'org_units';

    protected $guarded = [];

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

    /** The person named for a review step. Built-in slots still read the old columns. */
    public function reviewerIdForSlot(string $slot): ?int
    {
        if (in_array($slot, ['head_user_id', 'dean_user_id', 'vp_user_id'], true)) {
            return $this->{$slot} ? (int) $this->{$slot} : null;
        }

        $named = $this->relationLoaded('reviewers')
            ? $this->reviewers->firstWhere('slot', $slot)
            : $this->reviewers()->where('slot', $slot)->first();

        return $named?->user_id ? (int) $named->user_id : null;
    }

    /**
     * Who reviews a form in this unit. An empty Dean, VP, or extra step uses
     * the parent. Head does not: each unit's head is its own, or the step is
     * skipped.
     */
    public function reviewerIdForSlotInChain(string $slot): ?int
    {
        $named = $this->reviewerIdForSlot($slot);

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

            $named = $current->reviewerIdForSlot($slot);

            if ($named) {
                return $named;
            }
        }

        return null;
    }

    /**
     * The offices this person heads or reviews, plus every unit under those
     * offices, so a dean sees the departments and a VP sees the colleges.
     */
    public static function overseenIds(int $userId): array
    {
        $roots = static::query()
            ->where('head_user_id', $userId)
            ->orWhere('dean_user_id', $userId)
            ->orWhere('vp_user_id', $userId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return static::subtreeIds($roots);
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
