<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrReturnMark extends Model
{
    protected $table = 'pcr_return_marks';

    protected $guarded = [];

    public function form()
    {
        return $this->belongsTo(PcrForm::class, 'form_id');
    }

    public function statusLog()
    {
        return $this->belongsTo(PcrStatusLog::class, 'status_log_id');
    }
}
