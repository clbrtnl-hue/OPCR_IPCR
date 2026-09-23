<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrIndicator extends Model
{
    protected $table = 'pcr_indicators';

    protected $guarded = [];

    protected $casts = [
        'allotted_budget' => 'float',
        'progress_pct'    => 'integer',
        'target_date'     => 'date',
        'completed_on'    => 'date',
    ];

    protected $appends = ['delay'];

    public const PROGRESS_STATUSES = ['not_started', 'ongoing', 'completed', 'deferred'];

    public function output()
    {
        return $this->belongsTo(PcrOutput::class, 'output_id');
    }

    public function accomplishments()
    {
        return $this->hasMany(PcrAccomplishment::class, 'indicator_id');
    }

    public function ratings()
    {
        return $this->hasMany(PcrRating::class, 'indicator_id');
    }

    public function attachments()
    {
        return $this->hasManyThrough(
            PcrAttachment::class,
            PcrAccomplishment::class,
            'indicator_id',
            'accomplishment_id',
            'id',
            'id'
        );
    }

    public function parent()
    {
        return $this->belongsTo(PcrIndicator::class, 'parent_indicator_id');
    }

    public function children()
    {
        return $this->hasMany(PcrIndicator::class, 'parent_indicator_id');
    }

    public function assignments()
    {
        return $this->hasMany(PcrTargetAssignment::class, 'indicator_id');
    }

    /**
     * Where this task stands against its target date. Derived on read — a
     * stored flag would be wrong the moment the clock passed it.
     *
     * A finished task is judged on when it was delivered, not on today.
     */
    public function getDelayAttribute(): array
    {
        $due = $this->target_date;

        if (! $due) {
            return ['state' => 'no_date', 'days' => null, 'label' => 'No target date'];
        }

        $done = $this->progress_status === 'completed';
        $against = $done ? ($this->completed_on ?? $this->updated_at) : now();
        $days = (int) $due->startOfDay()->diffInDays($against->startOfDay(), false);

        if ($done) {
            return $days > 0
                ? ['state' => 'late', 'days' => $days, 'label' => "Completed {$days} day(s) late"]
                : ['state' => 'on_time', 'days' => abs($days), 'label' => 'Completed on time'];
        }

        if ($days > 0) {
            return ['state' => 'overdue', 'days' => $days, 'label' => "Overdue by {$days} day(s)"];
        }

        $left = abs($days);

        if ($left <= 7) {
            return ['state' => 'due_soon', 'days' => $left, 'label' => "Due in {$left} day(s)"];
        }

        return ['state' => 'on_track', 'days' => $left, 'label' => "Due in {$left} day(s)"];
    }
}
