<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage in routes/api.php:
     *   Route::middleware(['auth:api', 'role:admin'])->group(...);
     *   Route::middleware(['auth:api', 'role:admin,faculty'])->group(...);
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Admins can access every role-gated HMS route.
        if ($user->role !== 'admin' && ! in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'Forbidden. Required role: ' . implode('|', $roles),
            ], 403);
        }

        return $next($request);
    }
}
