<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEducation extends Model
{
    protected $table = 'user_educations';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
