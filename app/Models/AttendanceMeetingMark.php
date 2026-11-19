<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AttendanceMeetingMark extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['present', 'absent', 'excused'];

    protected $table = 'attendance_meeting_marks';

    protected $guarded = [];

    public function meeting()
    {
        return $this->belongsTo(AttendanceMeeting::class, 'meeting_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
