<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bumps the authenticated user's `last_seen_at` on each request.
 *
 *  - Throttled to at most one write per minute per user so a chatty
 *    SPA / mobile client doesn't hammer the DB on every poll.
 *  - Uses `saveQuietly()` to avoid firing model observers and to
 *    skip updating `updated_at` — `last_seen_at` is independent.
 *  - Never blocks the request; failure to write is silently swallowed
 *    so a transient DB hiccup can't take down API responses.
 *
 * IMPORTANT: The user is resolved BEFORE $next($request) so that
 * any file-upload request hasn't moved the PHP temp file yet.
 * Resolving auth after move_uploaded_file() causes Passport's auth
 * pipeline to try fopen() on the now-deleted temp path → RuntimeException.
 */
class TouchLastSeen
{
    /**
     * Throttle window in seconds. A user touched within this window
     * is not re-touched. 60s is a sweet spot: fresh enough for the
     * UI label, light enough on the DB.
     */
    protected const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        // Resolve the authenticated user BEFORE the controller runs.
        // This caches the guard result so we never re-trigger Passport's
        // auth pipeline (which reads the request body/files) after the
        // controller has already moved the upload temp file.
        $user        = $request->user('api') ?? $request->user();
        $now         = now();
        $shouldTouch = false;

        if ($user) {
            $last        = $user->last_seen_at;
            $shouldTouch = ! $last || $now->diffInSeconds($last) >= self::THROTTLE_SECONDS;
        }

        // Run the actual request — presence tracking must never slow it down.
        $response = $next($request);

        // Write last_seen_at after the response is built, using the already-
        // resolved $user (no second auth round-trip, no file access).
        if ($shouldTouch && $user) {
            try {
                $user->forceFill(['last_seen_at' => $now])->saveQuietly();

                Log::debug('[TouchLastSeen] bumped last_seen_at', [
                    'user_id' => $user->id,
                    'path'    => $request->path(),
                ]);
            } catch (\Throwable $e) {
                // Never break the request because of a presence write.
                Log::warning('TouchLastSeen failed', ['error' => $e->getMessage()]);
            }
        }

        return $response;
    }
}
