<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * AUTH-02 : mot de passe oublié (seul cas où un e-mail est envoyé).
 * AUTH-03 : politique de mot de passe.
 * AUTH-06 : changement de son propre mot de passe (obligatoire après une saisie par l'administration).
 */
class PasswordController extends Controller
{
    public static function rule(): PasswordRule
    {
        return PasswordRule::min(10)->mixedCase()->numbers()->symbols();
    }

    /** Réponse identique que le compte existe ou non, pour ne pas révéler les adresses inscrites. */
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->where('is_active', true)->first();

        if ($user) {
            $broker = Password::broker('users');
            if (! $broker->getRepository()->recentlyCreatedToken($user)) {
                $user->notify(new PasswordResetLink($broker->createToken($user)));
            }
        }

        return ['message' => 'Si un compte actif correspond à cette adresse, un e-mail vient de vous être envoyé.'];
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', self::rule()],
        ], [], ['password' => 'mot de passe']);

        $status = Password::broker('users')->reset($data, function (User $user, string $password) {
            abort_unless($user->is_active, 422, 'Ce compte est désactivé. Contactez les ressources humaines.');

            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            // Les sessions ouvertes ailleurs sont fermées
            DB::table('sessions')->where('user_id', $user->id)->delete();

            event(new PasswordReset($user));
            activity('auth')->causedBy($user)->log('Mot de passe réinitialisé');
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'token' => 'Ce lien est invalide ou a expiré (30 minutes). Refaites une demande de mot de passe oublié.',
            ]);
        }

        return ['message' => 'Votre mot de passe est enregistré. Vous pouvez vous connecter.'];
    }

    public function change(Request $request): UserResource
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', self::rule()],
        ], [
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l’actuel.',
        ], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        // Les autres sessions ouvertes sont fermées ; la session courante est conservée
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        activity('auth')->causedBy($user)->log('Mot de passe modifié');

        return new UserResource($user->load('employee'));
    }
}
