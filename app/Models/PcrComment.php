<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcrComment extends Model
{
    protected $table = 'pcr_comments';

    protected $guarded = [];

    public function form()
    {
        return $this->belongsTo(PcrForm::class, 'form_id');
    }

    public function indicator()
    {
        return $this->belongsTo(PcrIndicator::class, 'indicator_id');
    }

    public function mentions()
    {
        return $this->belongsToMany(User::class, 'pcr_comment_mentions', 'comment_id', 'user_id')
            ->withTimestamps();
    }
}
