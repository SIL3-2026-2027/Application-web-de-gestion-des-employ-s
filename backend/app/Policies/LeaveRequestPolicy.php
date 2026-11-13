<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->canViewAllEmployees()
            || $user->employee?->id === $request->employee_id
            || $request->employee->isManagedBy($user);
    }

    /**
     * Valider ou refuser : les RH à toute étape, le manager direct à la première étape.
     * Personne ne valide sa propre demande.
     */
    public function decide(User $user, LeaveRequest $request): bool
    {
        if (! $request->isAwaiting() || $user->employee?->id === $request->employee_id) {
            return false;
        }

        if ($user->isHr()) {
            return true;
        }

        return $request->status === LeaveRequest::PENDING && $request->employee->isManagedBy($user);
    }

    /** L'employé annule tant que ce n'est pas approuvé ; les RH peuvent aussi annuler une absence approuvée. */
    public function cancel(User $user, LeaveRequest $request): bool
    {
        if ($user->isHr()) {
            return in_array($request->status, LeaveRequest::ACTIVE_STATUSES, true);
        }

        return $request->isAwaiting() && $user->employee?->id === $request->employee_id;
    }
}
