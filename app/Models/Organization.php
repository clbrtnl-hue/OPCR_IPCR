<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

    protected $guarded = [];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function orgUnits()
    {
        return $this->hasMany(OrgUnit::class);
    }

    public function schoolYears()
    {
        return $this->hasMany(SchoolYear::class);
    }
}
