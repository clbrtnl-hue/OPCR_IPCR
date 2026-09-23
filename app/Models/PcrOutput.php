<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrOutput extends Model
{
    protected $table = 'pcr_outputs';

    protected $guarded = [];

    public const SECTIONS = ['strategic', 'core', 'support'];

    public function form()
    {
        return $this->belongsTo(PcrForm::class, 'form_id');
    }

    public function indicators()
    {
        return $this->hasMany(PcrIndicator::class, 'output_id')->orderBy('sort_order');
    }

    public function parentOutput()
    {
        return $this->belongsTo(PcrOutput::class, 'parent_output_id');
    }

    public function childOutputs()
    {
        return $this->hasMany(PcrOutput::class, 'parent_output_id');
    }
}
