<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PcrForm extends Model
{
    use BelongsToOrganization;

    protected $table = 'pcr_forms';

    protected $guarded = [];

    protected $appends = ['status_label'];

    /** Resolved this request only. Not a column. */
    public array $resolvedReviewerMap = [];

    protected $casts = [
        'submitted_at'   => 'datetime',
        'reviewed_at'      => 'datetime',
        'dean_reviewed_at' => 'datetime',
        'vp_reviewed_at'   => 'datetime',
        'rated_at'       => 'datetime',
        'commitment_approved_at' => 'datetime',
    ];

    public const STATUSES = [
        'draft', 'commitment_review', 'commitments_approved',
        'head_review', 'dean_review', 'director_review', 'vp_review',
        'qa_approval', 'approved', 'published',
        'qa_rating', 'rated', 'final', 'returned',
    ];

    public const EDITABLE_STATUSES = ['draft', 'returned'];

    public function schoolYear()
    {
        return $this->belongsTo(SchoolYear::class, 'school_year_id');
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function ratingPeriod()
    {
        return $this->belongsTo(RatingPeriod::class, 'rating_period_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Faculty and staff answer only what a head or VP assigned. They do not pick OPCR lines. */
    public function picksAssignedTargetsOnly(): bool
    {
        $this->loadMissing('owner');

        return $this->owner?->role === 'employee';
    }

    public function outputs()
    {
        return $this->hasMany(PcrOutput::class, 'form_id')->orderBy('sort_order');
    }

    public function comments()
    {
        return $this->hasMany(PcrComment::class, 'form_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(PcrStatusLog::class, 'form_id');
    }

    public function summaries()
    {
        return $this->hasMany(PcrPeriodSummary::class, 'form_id');
    }

    public function indicators()
    {
        return $this->hasManyThrough(
            PcrIndicator::class,
            PcrOutput::class,
            'form_id',
            'output_id',
            'id',
            'id'
        );
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function headReviewer()
    {
        return $this->belongsTo(User::class, 'head_reviewer_id');
    }

    public function deanReviewer()
    {
        return $this->belongsTo(User::class, 'dean_reviewer_id');
    }

    public function vpReviewer()
    {
        return $this->belongsTo(User::class, 'vp_reviewer_id');
    }

    public function commitmentApprover()
    {
        return $this->belongsTo(User::class, 'commitment_approver_id');
    }

    public function stageReviewers()
    {
        return $this->hasMany(PcrFormReviewer::class, 'form_id');
    }

    public function getStatusLabelAttribute(): string
    {
        foreach (app(\App\Services\WorkflowSettings::class)->reviewStages() as $stage) {
            if (($stage['status'] ?? '') === $this->status && ! empty($stage['label'])) {
                return $stage['label'];
            }
        }

        return [
            'draft'       => 'Draft',
            'commitment_review'    => 'For commitment approval',
            'commitments_approved' => 'Commitments approved',
            'head_review' => 'With Head',
            'dean_review'      => 'With Dean',
            'director_review'  => 'With Director',
            'vp_review'   => 'With VP',
            'qa_approval' => 'For QA approval',
            'approved'    => 'Approved',
            'published'   => 'Published',
            'qa_rating'   => 'With QA',
            'rated'       => 'Rated',
            'final'       => 'Final',
            'returned'    => 'Returned',
        ][$this->status] ?? (string) $this->status;
    }
}
