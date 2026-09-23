<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'height_m'      => 'float',
        'weight_kg'     => 'float',
    ];

    /** Government identifiers — personal data, withheld from the public card. */
    public const IDENTIFIERS = [
        'gsis_id', 'pagibig_id', 'philhealth_id', 'sss_id', 'tin', 'agency_employee_no',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
