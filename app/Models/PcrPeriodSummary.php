<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrPeriodSummary extends Model
{
    protected $table = 'pcr_period_summaries';

    protected $guarded = [];

    protected $casts = [
        'strategic_average' => 'float',
        'core_average'      => 'float',
        'support_average'   => 'float',
        'final_average'     => 'float',
    ];

    public function form()
    {
        return $this->belongsTo(PcrForm::class, 'form_id');
    }

    public function period()
    {
        return $this->belongsTo(RatingPeriod::class, 'rating_period_id');
    }
}
