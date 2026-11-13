<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): UserResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([
                'email' => "Trop de tentatives. Réessayez dans {$minutes} minute(s).",
            ]);
        }

        if (! Auth::guard('web')->attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages([
                'email' => 'Identifiants incorrects ou compte désactivé.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        activity('auth')->causedBy($user)->log('Connexion');

        return new UserResource($user->load('employee'));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['employee.position', 'employee.department']));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté']);
    }
}
