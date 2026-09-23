<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Token;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Those credentials do not match our records.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'This account is inactive. Ask an administrator to reactivate it.'], 403);
        }

        $token = $user->createToken('occ-pms')->accessToken;

        ActivityLog::record('User', $user->id, 'login', 'Signed in', null, $user);

        return response()->json([
            'access_token' => $token,
            'user'         => $user->load('orgUnit'),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('orgUnit'));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed|different:current_password',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'That is not your current password.',
                'errors'  => ['current_password' => ['That is not your current password.']],
            ], 422);
        }

        $user->forceFill(['password' => $data['password']])->save();

        $this->revokeOtherTokens($user, $request);

        ActivityLog::record('User', $user->id, 'password', 'Changed their own password');

        return response()->json(['data' => 'updated']);
    }

    private function revokeOtherTokens(User $user, Request $request): void
    {
        try {
            $current = $request->user()->token();
            $keep    = $current instanceof Token ? $current->id : null;

            $user->tokens()
                ->where('revoked', false)
                ->when($keep, fn ($query) => $query->where('id', '!=', $keep))
                ->update(['revoked' => true]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function logout(Request $request)
    {
        $token = $request->user()->token();

        if ($token) {
            $token->revoke();
        }

        return response()->json(['message' => 'Signed out.']);
    }
}
