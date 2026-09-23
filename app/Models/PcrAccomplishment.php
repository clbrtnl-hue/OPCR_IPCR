<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrAccomplishment extends Model
{
    protected $table = 'pcr_accomplishments';

    protected $guarded = [];

    public function indicator()
    {
        return $this->belongsTo(PcrIndicator::class, 'indicator_id');
    }

    public function period()
    {
        return $this->belongsTo(RatingPeriod::class, 'rating_period_id');
    }

    public function attachments()
    {
        return $this->hasMany(PcrAttachment::class, 'accomplishment_id');
    }
}
