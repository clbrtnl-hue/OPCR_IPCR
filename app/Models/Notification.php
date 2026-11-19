<?php

namespace App\Models;

use App\Mail\PmsNotificationMail;
use App\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    protected $casts = [
        'read_at'    => 'datetime',
        'trashed_at' => 'datetime',
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

        $body = static::plain($body);
        $link = static::formLink($link, $formId);
        $actorName = Auth::user()?->name;

        try {
            static::create([
                'user_id'    => $userId,
                'type'       => $type,
                'title'      => $title,
                'body'       => $body,
                'link'       => $link,
                'form_id'    => $formId,
                'actor_id'   => Auth::id(),
                'actor_name' => $actorName,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification failed: ' . $e->getMessage());

            return;
        }

        static::deliverMail($userId, $title, $body, $link, $actorName);
    }

    public static function sendMany(array $userIds, string $type, string $title, ?string $body = null, ?int $formId = null, ?string $link = null): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            static::send($userId, $type, $title, $body, $formId, $link);
        }
    }

    /**
     * A system reminder. Sent once per person and link, even if that person
     * happens to be the signed-in user when the job runs. A row in Trash still
     * counts, so deleting it from the inbox does not mail it again. The daily
     * purge removes that row after 30 days, and a later run may send it then.
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

        $body = static::plain($body);
        $link = static::formLink($link, $formId);

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
                'body'    => $body,
                'link'    => $link,
                'form_id' => $formId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification failed: ' . $e->getMessage());

            return false;
        }

        static::deliverMail($userId, $title, $body, $link);

        return true;
    }

    /**
     * Notifications only. Rows sit in Trash until this removes them.
     */
    public static function purgeTrash(int $days = 30): int
    {
        return static::query()
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', now()->subDays($days))
            ->delete();
    }

    /**
     * The in-app row is already saved. A mail failure is logged and does not
     * undo the bell, so a review or reminder still completes.
     */
    private static function deliverMail(int $userId, string $title, ?string $body, ?string $link, ?string $actorName = null): void
    {
        try {
            $email = User::query()->whereKey($userId)->value('email');

            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            Mail::to($email)->send(new PmsNotificationMail(
                headline: $title,
                detail: $body,
                actionUrl: static::absoluteLink($link),
                actorName: $actorName,
            ));
        } catch (\Throwable $e) {
            Log::warning('Notification email failed: ' . $e->getMessage());
        }
    }

    private static function plain(?string $body): ?string
    {
        return $body ? mb_substr(Html::toText($body), 0, 500) : null;
    }

    private static function formLink(?string $link, ?int $formId): ?string
    {
        return $link ?? ($formId ? "/forms/{$formId}" : null);
    }

    private static function absoluteLink(?string $link): ?string
    {
        if (! $link) {
            return null;
        }

        if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            return $link;
        }

        return url($link);
    }
}
