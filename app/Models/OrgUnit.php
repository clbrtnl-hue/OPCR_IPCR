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

    public function vp()
    {
        return $this->belongsTo(User::class, 'vp_user_id');
    }

    public function members()
    {
        return $this->hasMany(User::class, 'org_unit_id');
    }

    /**
     * The offices this person heads or reviews, plus every unit under those
     * offices, so a dean sees the departments and a VP sees the colleges.
     */
    public static function overseenIds(int $userId): array
    {
        $roots = static::query()
            ->where('head_user_id', $userId)
            ->orWhere('vp_user_id', $userId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return static::subtreeIds($roots);
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
