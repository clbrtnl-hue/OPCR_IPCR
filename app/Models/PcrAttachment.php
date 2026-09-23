<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrAttachment extends Model
{
    protected $table = 'pcr_attachments';

    protected $guarded = [];

    public function accomplishment()
    {
        return $this->belongsTo(PcrAccomplishment::class, 'accomplishment_id');
    }
}
