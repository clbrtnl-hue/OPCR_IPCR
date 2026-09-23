<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatingPeriod extends Model
{
    protected $table = 'rating_periods';

    protected $guarded = [];

    protected $casts = [
        'opens_at'  => 'date',
        'closes_at' => 'date',
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function schoolYear()
    {
        return $this->belongsTo(SchoolYear::class, 'school_year_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * A locked period is view-only for everyone but an administrator: no
     * accomplishments, no evidence, no progress, no rating.
     */
    public function isLockedFor(?User $user): bool
    {
        if ($user && $user->isAdmin()) {
            return false;
        }

        return (bool) $this->is_locked;
    }
}
