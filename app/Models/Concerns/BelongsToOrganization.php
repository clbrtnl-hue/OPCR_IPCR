<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies tenant isolation to a model: every query is filtered to the current
 * organization and every new record is stamped with it. Controllers, reports and
 * the review queues inherit isolation without each remembering a where clause.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $id = app(CurrentOrganization::class)->id();

            if ($id !== null) {
                $query->where($query->getModel()->getTable() . '.organization_id', $id);
            }
        });

        static::creating(function ($model) {
            if ($model->organization_id === null) {
                $model->organization_id = app(CurrentOrganization::class)->id();
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /** Escape hatch for admin tooling that must see across tenants. */
    public function scopeAcrossOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }
}
