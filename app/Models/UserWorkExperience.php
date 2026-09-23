<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserWorkExperience extends Model
{
    protected $table = 'user_work_experiences';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
