<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Circuit des demandes de congés (CNG-02, CNG-03, CNG-06). */
class LeaveRequestService
{
    public function __construct(
        private readonly WorkingDays $workingDays,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveNotifier $notifier,
    ) {}

    /**
     * Nombre de jours et contrôles, sans rien enregistrer (aperçu dans le formulaire).
     *
     * @return array{days: float, remaining: float|null, errors: array<string, string>}
     */
    public function evaluate(Employee $employee, LeaveType $type, array $data, ?User $actor = null): array
    {
        $days = $this->workingDays->count($data['start_date'], $data['end_date'], (bool) ($data['start_half_day'] ?? false), (bool) ($data['end_half_day'] ?? false));
        $errors = [];

        if ($days <= 0) {
            $errors['end_date'] = 'La période ne contient aucun jour ouvré.';
        }

        $overlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->active()
            ->overlapping($data['start_date'], $data['end_date'])
            ->exists();
        if ($overlap) {
            $errors['start_date'] = 'Une autre demande existe déjà sur cette période.';
        }

        $remaining = null;
        if ($type->is_deductible) {
            $year = CarbonImmutable::parse($data['start_date'])->year;
            $remaining = $this->balances->remaining($employee, $type, $year);
            // Les RH peuvent accorder une avance sur solde ; l'employé non.
            if ($days > $remaining && ! $actor?->isHr()) {
                $errors['leave_type_id'] = "Solde insuffisant : {$remaining} jour(s) disponible(s) pour {$days} demandé(s).";
            }
        }

        return ['days' => $days, 'remaining' => $remaining, 'errors' => $errors];
    }

    public function create(User $actor, Employee $employee, LeaveType $type, array $data, ?UploadedFile $attachment = null): LeaveRequest
    {
        // Saisie par les RH pour un autre employé (absence, maladie…) : approuvée d'office.
        $declaredByHr = $actor->isHr() && $actor->employee?->id !== $employee->id;

        $leave = DB::transaction(function () use ($actor, $employee, $type, $data, $attachment, $declaredByHr) {
            // Verrou sur l'employé : deux demandes simultanées ne peuvent pas dépasser le solde ni se chevaucher.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $result = $this->evaluate($employee, $type, $data, $actor);
            if ($result['errors']) {
                throw ValidationException::withMessages($result['errors']);
            }

            $request = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'start_half_day' => (bool) ($data['start_half_day'] ?? false),
                'end_half_day' => (bool) ($data['end_half_day'] ?? false),
                'days' => $result['days'],
                'reason' => $data['reason'] ?? null,
                'status' => $declaredByHr ? LeaveRequest::APPROVED : LeaveRequest::PENDING,
                'created_by' => $actor->id,
                'hr_decision_by' => $declaredByHr ? $actor->id : null,
                'hr_decided_at' => $declaredByHr ? now() : null,
            ]);

            if ($attachment) {
                $request->update(['attachment_path' => $attachment->store("leave-attachments/{$employee->id}", 'local')]);
            }

            return $request;
        });

        // Notifications envoyées une fois la demande enregistrée (hors transaction)
        $declaredByHr
            ? $this->notifier->decided($leave, $actor, declaredByHr: true)
            : $this->notifier->awaitingApproval($leave);

        return $leave;
    }

    public function approve(User $actor, LeaveRequest $request): LeaveRequest
    {
        if ($actor->isHr()) {
            $request->update([
                'status' => LeaveRequest::APPROVED,
                'hr_decision_by' => $actor->id,
                'hr_decided_at' => now(),
            ]);
        } else {
            $request->update([
                'status' => $request->leaveType->requires_hr_approval ? LeaveRequest::MANAGER_APPROVED : LeaveRequest::APPROVED,
                'manager_decision_by' => $actor->id,
                'manager_decided_at' => now(),
            ]);
        }

        $this->notifier->decided($request, $actor);

        return $request;
    }

    public function reject(User $actor, LeaveRequest $request, string $reason): LeaveRequest
    {
        $fields = $actor->isHr()
            ? ['hr_decision_by' => $actor->id, 'hr_decided_at' => now()]
            : ['manager_decision_by' => $actor->id, 'manager_decided_at' => now()];

        $request->update([...$fields, 'status' => LeaveRequest::REFUSED, 'refusal_reason' => $reason]);
        $this->notifier->decided($request, $actor);

        return $request;
    }

    public function cancel(User $actor, LeaveRequest $request): LeaveRequest
    {
        $request->update(['status' => LeaveRequest::CANCELLED]);
        $this->notifier->decided($request, $actor); // prévient l'employé si ce sont les RH qui annulent

        return $request;
    }
}
