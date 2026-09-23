<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrRating extends Model
{
    protected $table = 'pcr_ratings';

    protected $guarded = [];

    protected $casts = [
        'a' => 'float',
    ];

    public const DIMENSIONS = ['q', 'e', 't'];

    public function indicator()
    {
        return $this->belongsTo(PcrIndicator::class, 'indicator_id');
    }

    public function period()
    {
        return $this->belongsTo(RatingPeriod::class, 'rating_period_id');
    }
}
