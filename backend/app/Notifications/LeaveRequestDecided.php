<?php

namespace App\Notifications;

use App\Models\LeaveRequest;

/** Réponse à une demande de congé, ou absence enregistrée par les RH, envoyée à l'employé. */
class LeaveRequestDecided extends AppNotification
{
    public function __construct(public LeaveRequest $leave, public bool $declaredByHr = false) {}

    public function category(): string
    {
        return 'leave_decision';
    }

    public function title(): string
    {
        $type = $this->leave->leaveType->name;

        return match (true) {
            $this->declaredByHr => "{$type} enregistré par les RH",
            $this->leave->status === LeaveRequest::APPROVED => "Votre demande de {$type} est approuvée",
            $this->leave->status === LeaveRequest::MANAGER_APPROVED => "Votre demande de {$type} est validée par votre manager",
            $this->leave->status === LeaveRequest::REFUSED => "Votre demande de {$type} est refusée",
            default => "Votre demande de {$type} est annulée",
        };
    }

    public function message(): string
    {
        $l = $this->leave;
        $period = "du {$l->start_date->format('d/m/Y')} au {$l->end_date->format('d/m/Y')}";

        return match (true) {
            $this->declaredByHr => "Une absence ({$l->leaveType->name}) a été enregistrée pour vous {$period}.",
            $l->status === LeaveRequest::MANAGER_APPROVED => "Votre demande {$period} est validée par votre manager. Elle attend maintenant la validation des RH.",
            $l->status === LeaveRequest::REFUSED => "Votre demande {$period} est refusée. Motif : {$l->refusal_reason}",
            $l->status === LeaveRequest::CANCELLED => "Votre demande {$period} a été annulée par les RH.",
            default => "Votre demande {$period} est approuvée. Bon congé !",
        };
    }

    public function url(): string
    {
        return '/conges';
    }
}
