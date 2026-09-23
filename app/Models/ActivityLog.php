<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * `$actor` is for the moments when nobody is signed in yet — a sign-in is
     * performed by the person signing in, not by the system, and reading
     * Auth::id() there records nobody.
     */
    public static function record(
        string $subjectType,
        ?int $subjectId,
        string $action,
        ?string $description = null,
        ?array $changes = null,
        $actor = null,
    ): void {
        try {
            $actor = $actor ?: Auth::user();

            static::create([
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'action'       => $action,
                'description'  => $description ? mb_substr(Html::toText($description), 0, 500) : null,
                'changes'      => $changes ?: null,
                'user_id'      => $actor?->id,
                'user_name'    => $actor?->name,
                'ip_address'   => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Activity log failed: ' . $e->getMessage());
        }
    }

    public static function diff(Model $model, array $newValues): array
    {
        $changes = [];
        foreach ($newValues as $field => $to) {
            $from = $model->getOriginal($field);
            if ((string) $from !== (string) $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }
}
