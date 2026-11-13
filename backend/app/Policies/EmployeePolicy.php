<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // la liste est filtrée par Employee::scopeVisibleTo
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->canViewAllEmployees()) {
            return true;
        }

        $own = $user->employee;

        return $own && ($own->id === $employee->id || $employee->manager_id === $own->id);
    }

    /** Données sensibles (n° de sécurité sociale, IBAN) : RH ou l'employé lui-même. */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $user->isHr() || $user->employee?->id === $employee->id;
    }

    public function create(User $user): bool
    {
        return $user->isHr();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->isHr();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->isHr();
    }
}
