<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrFormReviewer extends Model
{
    protected $table = 'pcr_form_reviewers';

    protected $guarded = [];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function form()
    {
        return $this->belongsTo(PcrForm::class, 'form_id');
    }

    /** Remember who was resolved for each named stage. Null means the step is skipped. */
    public static function sync(PcrForm $form, array $map): void
    {
        foreach ($map as $stage => $reviewerId) {
            static::updateOrCreate(
                ['form_id' => $form->id, 'stage' => $stage],
                ['reviewer_id' => $reviewerId]
            );
        }
    }
}
