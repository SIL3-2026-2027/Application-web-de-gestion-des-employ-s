<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestDecided;
use App\Notifications\LeaveRequestToApprove;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/** Qui prévenir à chaque étape d'une demande de congé. */
class LeaveNotifier
{
    /** La demande attend une validation : manager direct d'abord, RH ensuite (ou si pas de manager). */
    public function awaitingApproval(LeaveRequest $leave): void
    {
        $leave->loadMissing(['employee.manager.user', 'leaveType']);
        $managerUser = $leave->employee->manager?->user;

        $recipients = $leave->status === LeaveRequest::PENDING && $managerUser?->is_active
            ? collect([$managerUser])
            : User::hrRecipients();

        $this->send($this->except($recipients, $leave->employee->user_id), new LeaveRequestToApprove($leave));
    }

    /** Décision (ou saisie RH) communiquée à l'employé, sauf s'il en est l'auteur. */
    public function decided(LeaveRequest $leave, User $actor, bool $declaredByHr = false): void
    {
        $leave->loadMissing(['employee.user', 'leaveType']);
        $employeeUser = $leave->employee->user;

        if ($employeeUser && $employeeUser->is_active && $employeeUser->id !== $actor->id) {
            $this->send(collect([$employeeUser]), new LeaveRequestDecided($leave, $declaredByHr));
        }

        // Validée par le manager : c'est maintenant aux RH d'agir
        if ($leave->status === LeaveRequest::MANAGER_APPROVED) {
            $this->awaitingApproval($leave);
        }
    }

    private function except(Collection $users, ?int $userId): Collection
    {
        return $users->reject(fn (User $u) => $u->id === $userId)->values();
    }

    private function send(Collection $users, object $notification): void
    {
        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);
        }
    }
}
