<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OrgUnit extends Model
{
    use BelongsToOrganization;

    protected $table = 'org_units';

    protected $guarded = [];

    public function parent()
    {
        return $this->belongsTo(OrgUnit::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(OrgUnit::class, 'parent_id');
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function vp()
    {
        return $this->belongsTo(User::class, 'vp_user_id');
    }

    public function members()
    {
        return $this->hasMany(User::class, 'org_unit_id');
    }
}
