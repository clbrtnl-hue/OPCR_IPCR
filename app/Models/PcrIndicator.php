<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrIndicator extends Model
{
    protected $table = 'pcr_indicators';

    protected $guarded = [];

    protected $casts = [
        'allotted_budget'            => 'float',
        'progress_pct'               => 'integer',
        'target_date'                => 'date',
        'completed_on'               => 'date',
        'closed_at'                  => 'datetime',
        'line_submitted_at'          => 'datetime',
        'line_approved_at'           => 'datetime',
        'needs_commitment_approval'  => 'boolean',
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

    /** Commitments still in force. A replaced line stays on the form as a record. */
    public function openChildren()
    {
        return $this->children()->whereNull('closed_at');
    }

    /** A replaced line stays stored. It is no longer the commitment in force. */
    public function isInForce(): bool
    {
        return ! $this->closed_at && $this->replacement_status !== 'pending';
    }

    /** Closed lines, pending replacements, and unapproved restarts are not scored. */
    public function countsTowardRating(): bool
    {
        if (! $this->isInForce()) {
            return false;
        }

        return ! $this->needs_commitment_approval || (bool) $this->line_approved_at;
    }

    public function scopeInForce($query)
    {
        return $query->whereNull('closed_at')
            ->where(function ($inner) {
                $inner->whereNull('replacement_status')
                    ->orWhere('replacement_status', '!=', 'pending');
            });
    }

    public function scopeRateable($query)
    {
        return $query->inForce()
            ->where(function ($inner) {
                $inner->where('needs_commitment_approval', false)
                    ->orWhereNotNull('line_approved_at');
            });
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
