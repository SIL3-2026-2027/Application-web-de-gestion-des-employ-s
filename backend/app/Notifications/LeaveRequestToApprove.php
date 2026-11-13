<?php

namespace App\Notifications;

use App\Models\LeaveRequest;

/** Une demande de congé attend la validation du destinataire (manager ou RH). */
class LeaveRequestToApprove extends AppNotification
{
    public function __construct(public LeaveRequest $leave) {}

    public function category(): string
    {
        return 'leave_to_approve';
    }

    public function title(): string
    {
        return "Demande de congé à valider — {$this->leave->employee->full_name}";
    }

    public function message(): string
    {
        $l = $this->leave;
        $step = $l->status === LeaveRequest::MANAGER_APPROVED ? ' Elle a déjà été validée par le manager.' : '';

        return "{$l->employee->full_name} demande {$this->days()} de {$l->leaveType->name} "
            ."du {$l->start_date->format('d/m/Y')} au {$l->end_date->format('d/m/Y')}.".$step;
    }

    public function url(): string
    {
        return '/conges/validations';
    }

    private function days(): string
    {
        $d = rtrim(rtrim(number_format($this->leave->days, 1, ',', ''), '0'), ',');

        return $d.' jour'.($this->leave->days > 1 ? 's' : '');
    }
}
