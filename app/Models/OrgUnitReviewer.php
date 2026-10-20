<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgUnitReviewer extends Model
{
    protected $table = 'org_unit_reviewers';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }
}
