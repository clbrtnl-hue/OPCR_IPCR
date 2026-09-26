<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function send(
        ?int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $formId = null,
        ?string $link = null,
    ): void {
        if (! $userId || $userId === Auth::id()) {
            return;
        }

        try {
            static::create([
                'user_id'    => $userId,
                'type'       => $type,
                'title'      => $title,
                'body'       => $body ? mb_substr(Html::toText($body), 0, 500) : null,
                'link'       => $link ?? ($formId ? "/forms/{$formId}" : null),
                'form_id'    => $formId,
                'actor_id'   => Auth::id(),
                'actor_name' => Auth::user()->name ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification failed: ' . $e->getMessage());
        }
    }

    public static function sendMany(array $userIds, string $type, string $title, ?string $body = null, ?int $formId = null, ?string $link = null): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            static::send($userId, $type, $title, $body, $formId, $link);
        }
    }

    /**
     * A system reminder. Sent once per person and link, even if that person
     * happens to be the signed-in user when the job runs.
     */
    public static function remind(
        ?int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $formId = null,
        ?string $link = null,
    ): bool {
        if (! $userId) {
            return false;
        }

        $link = $link ?? ($formId ? "/forms/{$formId}" : null);

        $already = static::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('link', $link)
            ->exists();

        if ($already) {
            return false;
        }

        try {
            static::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body ? mb_substr(Html::toText($body), 0, 500) : null,
                'link'    => $link,
                'form_id' => $formId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification failed: ' . $e->getMessage());

            return false;
        }

        return true;
    }
}
