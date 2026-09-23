<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\PersonName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use BelongsToOrganization;

    public const ROLES = ['admin', 'president', 'qa', 'vp', 'program_head', 'employee'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'status',
        'image', 'position_title', 'org_unit_id',
        'prefix', 'first_name', 'middle_initial', 'last_name', 'suffix', 'credentials',
    ];

    public const NAME_PARTS = [
        'prefix', 'first_name', 'middle_initial', 'last_name', 'suffix', 'credentials',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['capabilities'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_seen_at'      => 'datetime',
        'password'          => 'hashed',
    ];

    /**
     * `name` is what every signature, log line and avatar reads, so the parts
     * stay the source of truth and it is recomposed from them. An account saved
     * with a name and no parts — a factory, a seeder — keeps the name it was given.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if (! collect(self::NAME_PARTS)->contains(fn ($part) => filled($user->{$part}))) {
                return;
            }

            $user->middle_initial = PersonName::initial($user->middle_initial);
            $user->name = PersonName::compose($user->only(self::NAME_PARTS));
        });
    }

    /** Lists read as a registry: surname first, then given name. */
    public function scopeOrderByPerson($query)
    {
        return $query
            ->orderByRaw('COALESCE(NULLIF(last_name, \'\'), name)')
            ->orderByRaw('COALESCE(NULLIF(first_name, \'\'), name)');
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function headedUnits()
    {
        return $this->hasMany(OrgUnit::class, 'head_user_id');
    }

    public function overseenUnits()
    {
        return $this->hasMany(OrgUnit::class, 'vp_user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * What this person may do under the organization's current delegation
     * rules. Sent with the account so the interface offers exactly what the
     * server would accept, rather than showing controls that 403.
     */
    public function getCapabilitiesAttribute(): array
    {
        if (! $this->role) {
            return ['assign_outputs' => false, 'assign_indicators' => false];
        }

        $rules = app(\App\Services\WorkflowSettings::class);

        return [
            'assign_outputs'    => $this->isAdmin() || (! $rules->isTerminalRole($this->role) && $rules->mayAssignOutputs($this->role)),
            'assign_indicators' => $this->isAdmin() || (! $rules->isTerminalRole($this->role) && $rules->mayAssignIndicators($this->role)),
        ];
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function educations()
    {
        return $this->hasMany(UserEducation::class)->orderBy('sort_order');
    }

    public function eligibilities()
    {
        return $this->hasMany(UserEligibility::class)->orderBy('sort_order');
    }

    public function workExperiences()
    {
        return $this->hasMany(UserWorkExperience::class)->orderBy('sort_order');
    }

    public function trainings()
    {
        return $this->hasMany(UserTraining::class)->orderBy('sort_order');
    }

    public function voluntaryWorks()
    {
        return $this->hasMany(UserVoluntaryWork::class)->orderBy('sort_order');
    }
}
