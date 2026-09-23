<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrTargetAssignment extends Model
{
    protected $table = 'pcr_target_assignments';

    protected $guarded = [];

    public function indicator()
    {
        return $this->belongsTo(PcrIndicator::class, 'indicator_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
