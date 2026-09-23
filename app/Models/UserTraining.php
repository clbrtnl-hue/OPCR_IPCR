<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTraining extends Model
{
    protected $table = 'user_trainings';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
