<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserVoluntaryWork extends Model
{
    protected $table = 'user_voluntary_works';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
