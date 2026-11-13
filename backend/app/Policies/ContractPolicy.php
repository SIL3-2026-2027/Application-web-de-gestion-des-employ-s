<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    /** Consultation : RH, direction, l'employé lui-même et son manager. */
    public function view(User $user, Contract $contract): bool
    {
        return $user->canViewAllEmployees()
            || $user->employee?->id === $contract->employee_id
            || $contract->employee->isManagedBy($user);
    }

    /** Salaire : pas pour le manager. */
    public function viewSalary(User $user, Contract $contract): bool
    {
        return $user->canViewAllEmployees() || $user->employee?->id === $contract->employee_id;
    }

    /** Documents (PDF générés, exemplaire signé, certificat) : RH et l'employé concerné. */
    public function viewDocuments(User $user, Contract $contract): bool
    {
        return $user->isHr() || $user->employee?->id === $contract->employee_id;
    }

    public function manage(User $user): bool
    {
        return $user->isHr();
    }
}
