<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use BelongsToOrganization;

    public const VISIBILITY = ['only_me', 'selected', 'everyone', 'role'];

    public const AUDIENCE_ROLES = ['program_head', 'employee', 'vp', 'qa'];

    public const SCHEDULE_ONLY = ['admin', 'president', 'vp'];

    public const COLLEGE_WIDE = ['admin', 'president'];

    protected $table = 'calendar_events';

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'all_day'   => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function viewers()
    {
        return $this->belongsToMany(User::class, 'calendar_event_users', 'event_id', 'user_id')
            ->withTimestamps();
    }

    public function category()
    {
        return $this->belongsTo(AttendanceCategory::class, 'category_id');
    }

    public function marks()
    {
        return $this->hasMany(CalendarEventMark::class, 'event_id');
    }

    public function counts(): bool
    {
        return in_array($this->visibility, ['selected', 'everyone'], true)
            && $this->category_id
            && $this->rating_period_id;
    }

    /**
     * @return array<int, string>
     */
    public function dates(): array
    {
        return AttendanceDay::days(
            $this->starts_at->copy()->startOfDay(),
            $this->ends_at->copy()->startOfDay(),
        );
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $leaders = OrgUnit::leaderIdsForMember($user->org_unit_id ? (int) $user->org_unit_id : null);

            $query->where('created_by', $user->id)
                ->orWhere(function (Builder $query) use ($leaders) {
                    $query->where('visibility', 'everyone')
                        ->where(function (Builder $query) use ($leaders) {
                            $query->whereIn('created_by', $leaders !== [] ? $leaders : [0])
                                ->orWhereHas('owner', fn (Builder $query) => $query->whereIn('role', self::COLLEGE_WIDE));
                        });
                })
                ->orWhere(function (Builder $query) use ($user) {
                    $query->where('visibility', 'selected')
                        ->whereHas('viewers', fn (Builder $query) => $query->where('users.id', $user->id));
                })
                ->orWhere(function (Builder $query) use ($user) {
                    $query->where('visibility', 'role')
                        ->where('audience_role', $user->role);
                });
        });
    }
}
