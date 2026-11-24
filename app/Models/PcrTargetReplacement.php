<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrTargetReplacement extends Model
{
    protected $table = 'pcr_target_replacements';

    protected $guarded = [];

    protected $casts = [
        'decided_at'         => 'datetime',
        'proposed_user_ids'  => 'array',
    ];

    public function oldIndicator()
    {
        return $this->belongsTo(PcrIndicator::class, 'old_indicator_id');
    }

    public function newIndicator()
    {
        return $this->belongsTo(PcrIndicator::class, 'new_indicator_id');
    }
}
