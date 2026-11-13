<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADM-01 : comptes utilisateurs, créés uniquement par l'administration,
 * qui saisit directement le mot de passe (aucun e-mail à l'inscription).
 *
 * Règles :
 * - l'administrateur attribue tous les rôles ; les RH n'attribuent que manager, employé et direction,
 *   et ne modifient pas les comptes RH ou administrateur ;
 * - personne ne se désactive soi-même ni ne retire son propre rôle d'administrateur ;
 * - il reste toujours au moins un administrateur actif.
 */
class AccountService
{
    /** @return list<string> */
    public function assignableRoles(User $actor): array
    {
        return $actor->hasRole(User::ROLE_ADMIN)
            ? User::ROLES
            : [User::ROLE_MANAGER, User::ROLE_EMPLOYE, User::ROLE_DIRECTION];
    }

    public function canManage(User $actor, User $target): bool
    {
        if ($actor->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        return $actor->hasRole(User::ROLE_RH)
            && ! $target->hasAnyRole([User::ROLE_ADMIN, User::ROLE_RH]);
    }

    /**
     * Crée le compte, rattaché ou non à un dossier employé.
     *
     * @param  array{email: string, password: string, name?: string|null, roles: list<string>, employee_id?: int|null, must_change_password?: bool}  $data
     */
    public function create(User $actor, array $data): User
    {
        $this->checkRoles($actor, $data['roles']);
        $employee = isset($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : null;

        if ($employee?->user_id) {
            throw ValidationException::withMessages(['employee_id' => 'Cet employé a déjà un compte.']);
        }
        if ($employee?->status === 'sorti') {
            throw ValidationException::withMessages(['employee_id' => 'Cet employé a quitté l’entreprise.']);
        }

        $user = DB::transaction(function () use ($data, $employee) {
            $user = User::create([
                'name' => $employee?->full_name ?? $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'must_change_password' => $data['must_change_password'] ?? true,
            ])->save();
            $user->syncRoles($data['roles']);
            $employee?->update(['user_id' => $user->id]);

            return $user;
        });

        activity('account')->causedBy($actor)->performedOn($user)->withProperties(['roles' => $data['roles']])->log('Compte créé');

        return $user;
    }

    /** @param  list<string>  $roles */
    public function update(User $actor, User $user, array $roles, string $email): User
    {
        $this->checkRoles($actor, $roles);

        if ($actor->is($user) && $user->hasRole(User::ROLE_ADMIN) && ! in_array(User::ROLE_ADMIN, $roles, true)) {
            throw ValidationException::withMessages(['roles' => 'Vous ne pouvez pas retirer votre propre rôle d’administrateur.']);
        }
        if ($user->hasRole(User::ROLE_ADMIN) && ! in_array(User::ROLE_ADMIN, $roles, true)) {
            $this->ensureAnotherAdmin($user);
        }

        DB::transaction(function () use ($user, $roles, $email) {
            $user->update(['email' => $email]);
            $user->syncRoles($roles);
        });

        activity('account')->causedBy($actor)->performedOn($user)->withProperties(['roles' => $roles, 'email' => $email])->log('Compte modifié');

        return $user;
    }

    /** L'administration redéfinit le mot de passe (employé sans e-mail, oubli signalé au guichet…). */
    public function setPassword(User $actor, User $user, string $password, bool $mustChange): User
    {
        $user->forceFill(['password' => $password, 'must_change_password' => $mustChange])->save();
        $this->closeSessions($user);

        activity('account')->causedBy($actor)->performedOn($user)->log('Mot de passe redéfini par l’administration');

        return $user;
    }

    public function setActive(User $actor, User $user, bool $active): User
    {
        if (! $active) {
            if ($actor->is($user)) {
                throw ValidationException::withMessages(['user' => 'Vous ne pouvez pas désactiver votre propre compte.']);
            }
            if ($user->hasRole(User::ROLE_ADMIN)) {
                $this->ensureAnotherAdmin($user);
            }
        }

        if ($active && $user->employee?->status === 'sorti') {
            throw ValidationException::withMessages(['user' => 'Cet employé a quitté l’entreprise : son compte ne peut pas être réactivé.']);
        }

        $user->update(['is_active' => $active]);

        if (! $active) {
            $this->closeSessions($user);
        }

        activity('account')->causedBy($actor)->performedOn($user)->log($active ? 'Compte réactivé' : 'Compte désactivé');

        return $user;
    }

    /** Déconnexion immédiate : suppression des sessions ouvertes et des jetons. */
    public function closeSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    /** @param  list<string>  $roles */
    private function checkRoles(User $actor, array $roles): void
    {
        $forbidden = array_diff($roles, $this->assignableRoles($actor));
        if ($forbidden) {
            throw ValidationException::withMessages(['roles' => 'Vous ne pouvez pas attribuer le rôle : '.implode(', ', $forbidden).'.']);
        }
    }

    private function ensureAnotherAdmin(User $user): void
    {
        $others = User::role(User::ROLE_ADMIN)->where('is_active', true)->whereKeyNot($user->id)->exists();
        if (! $others) {
            throw ValidationException::withMessages(['roles' => 'Il doit rester au moins un administrateur actif.']);
        }
    }
}
