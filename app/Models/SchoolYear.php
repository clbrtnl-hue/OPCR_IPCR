<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SchoolYear extends Model
{
    use BelongsToOrganization;

    protected $table = 'school_years';

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    public function periods()
    {
        return $this->hasMany(RatingPeriod::class, 'school_year_id')->orderBy('seq');
    }

    public function forms()
    {
        return $this->hasMany(PcrForm::class, 'school_year_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
