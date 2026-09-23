<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEligibility extends Model
{
    protected $table = 'user_eligibilities';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
