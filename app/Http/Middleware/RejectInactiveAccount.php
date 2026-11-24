<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\InactiveAccount;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated person may still be holding a token. The next request
 * ends that session with the same message the sign-in screen uses.
 */
class RejectInactiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            $fromToken = $this->userForBearer($bearer);

            if ($fromToken && $fromToken->status !== 'active') {
                app(InactiveAccount::class)->revokeTokens($fromToken);

                return $this->denied();
            }

            // A bearer is authenticated later. Resolving it here would reject a
            // revoked token before we can tell an inactive account from an
            // expired session.
            return $next($request);
        }

        $user = $request->user('api') ?? $request->user();

        if ($user instanceof User && $user->status !== 'active') {
            app(InactiveAccount::class)->revokeTokens($user);

            return $this->denied();
        }

        return $next($request);
    }

    private function denied(): Response
    {
        return response()->json(['message' => InactiveAccount::MESSAGE], 403);
    }

    /**
     * The token id sits in the JWT payload. A revoked token still names
     * its owner, which is enough to explain the refusal. The signature
     * is checked later, when the account is active.
     */
    private function userForBearer(string $bearer): ?User
    {
        $parts = explode('.', $bearer);

        if (count($parts) < 2) {
            return null;
        }

        $payload = $parts[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $jti = is_array($claims) ? ($claims['jti'] ?? null) : null;

        if (! is_string($jti) || $jti === '') {
            return null;
        }

        $token = Token::query()->find($jti);

        if (! $token || ! $token->user_id) {
            return null;
        }

        return User::query()->withoutGlobalScope('organization')->find($token->user_id);
    }
}
