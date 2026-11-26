<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AttendanceCategory extends Model
{
    use BelongsToOrganization;

    public const STARTERS = [
        ['slug' => 'daily', 'name' => 'Everyday attendance', 'source' => 'daily', 'sort_order' => 0],
        ['slug' => 'meeting', 'name' => 'Meetings', 'source' => 'event', 'sort_order' => 1],
        ['slug' => 'school', 'name' => 'School activities', 'source' => 'event', 'sort_order' => 2],
        ['slug' => 'lgu', 'name' => 'LGU activities', 'source' => 'event', 'sort_order' => 3],
        ['slug' => 'convocation', 'name' => 'Convocation', 'source' => 'event', 'sort_order' => 4],
        ['slug' => 'training', 'name' => 'Trainings', 'source' => 'event', 'sort_order' => 5],
    ];

    protected $table = 'attendance_categories';

    protected $guarded = [];

    protected $casts = [
        'system' => 'boolean',
    ];

    public function rateKey(): string
    {
        if ($this->source === 'daily' || $this->slug === 'daily') {
            return 'daily';
        }

        if (array_key_exists($this->slug, AttendanceMeeting::CATEGORIES)) {
            return $this->slug;
        }

        return 'c' . $this->id;
    }

    /**
     * The built-in daily line and the five activity lines. A head can add more.
     */
    public static function ensure(): Collection
    {
        foreach (self::STARTERS as $starter) {
            static::query()->firstOrCreate(
                ['slug' => $starter['slug']],
                [
                    'name'       => $starter['name'],
                    'source'     => $starter['source'],
                    'system'     => true,
                    'sort_order' => $starter['sort_order'],
                ],
            );
        }

        return static::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Named head of an office. That is who records employee attendance. */
    public static function linkableBy(User $user): bool
    {
        if (in_array($user->role, ['admin', 'vp', 'president'], true)) {
            return false;
        }

        return OrgUnit::query()->whereHas('reviewers', function ($reviewers) use ($user) {
            $reviewers->where('user_id', $user->id)->where('slot', 'head_user_id');
        })->exists();
    }

    public static function manageableBy(User $user): bool
    {
        if (in_array($user->role, ['vp', 'president'], true)) {
            return false;
        }

        if (in_array($user->role, ['admin', 'qa', 'program_head'], true)) {
            return true;
        }

        return OrgUnit::query()->whereLedBy($user->id)->exists();
    }
}
