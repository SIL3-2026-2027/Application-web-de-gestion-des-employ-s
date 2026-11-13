<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifié à chaque requête authentifiée :
 * - un compte désactivé est déconnecté immédiatement ;
 * - un compte dont le mot de passe a été saisi par l'administration doit d'abord le changer.
 */
class EnsureAccountIsUsable
{
    /** Routes accessibles tant que le mot de passe n'a pas été changé. */
    private const ALLOWED_BEFORE_CHANGE = ['api/v1/me', 'api/v1/me/password', 'api/v1/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'Votre compte a été désactivé.'], 401);
        }

        if ($user?->must_change_password && ! in_array($request->path(), self::ALLOWED_BEFORE_CHANGE, true)) {
            return response()->json([
                'message' => 'Vous devez changer votre mot de passe avant de continuer.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
