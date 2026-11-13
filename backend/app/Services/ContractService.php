<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Règles métier des contrats et avenants (CTR-01, CTR-03, CTR-06). */
class ContractService
{
    public function create(User $actor, Employee $employee, array $data): Contract
    {
        $this->checkDates($data);
        $this->checkNoOverlap($employee, $data['start_date'], $data['end_date'] ?? null);

        return DB::transaction(function () use ($actor, $employee, $data) {
            $contract = $employee->contracts()->create([
                ...$data,
                'kind' => 'contrat',
                'created_by' => $actor->id,
            ]);
            $this->applyIfDue($contract);

            return $contract;
        });
    }

    public function update(Contract $contract, array $data): Contract
    {
        $this->checkDates($data);
        $this->checkNoOverlap($contract->employee, $data['start_date'], $data['end_date'] ?? null, $contract);

        if ($contract->amendments()->exists() && $data['start_date'] !== $contract->start_date->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'La date de début ne peut plus changer : des avenants existent.']);
        }

        $contract->update($data);

        return $contract;
    }

    /** CTR-03 : un avenant ne porte que les conditions modifiées. */
    public function amend(User $actor, Contract $contract, array $data): Contract
    {
        $effective = CarbonImmutable::parse($data['start_date']);
        $end = $contract->effectiveEndDate();

        if ($contract->ended_at) {
            throw ValidationException::withMessages(['start_date' => 'Ce contrat est terminé : il ne peut plus recevoir d’avenant.']);
        }
        if ($effective->lt($contract->start_date) || ($end && $effective->gt($end))) {
            throw ValidationException::withMessages(['start_date' => "La date d'effet doit être comprise dans la durée du contrat."]);
        }

        $changes = array_filter(array_intersect_key($data, array_flip(Contract::AMENDABLE)), fn ($v) => $v !== null && $v !== '');
        if ($changes === []) {
            throw ValidationException::withMessages(['description' => 'Indiquez au moins une modification (salaire, horaires, poste, département ou date de fin).']);
        }

        if (isset($changes['end_date'])) {
            if (! in_array($contract->type, Contract::FIXED_TERM_TYPES, true)) {
                throw ValidationException::withMessages(['end_date' => 'Un CDI n’a pas de date de fin.']);
            }
            if (CarbonImmutable::parse($changes['end_date'])->lt($effective)) {
                throw ValidationException::withMessages(['end_date' => "La nouvelle date de fin doit être postérieure à la date d'effet."]);
            }
        }

        return DB::transaction(function () use ($actor, $contract, $data, $changes) {
            $amendment = Contract::create([
                ...$changes,
                'employee_id' => $contract->employee_id,
                'parent_id' => $contract->id,
                'kind' => 'avenant',
                'type' => $contract->type,
                'start_date' => $data['start_date'],
                'description' => $data['description'] ?? null,
                'created_by' => $actor->id,
            ]);
            $this->applyIfDue($amendment);
            $contract->unsetRelation('amendments');

            return $amendment;
        });
    }

    /** CTR-06 : fin de contrat, avec départ éventuel de l'employé. */
    public function end(Contract $contract, array $data): Contract
    {
        $endedAt = CarbonImmutable::parse($data['ended_at']);

        if ($contract->ended_at) {
            throw ValidationException::withMessages(['ended_at' => 'Ce contrat est déjà terminé.']);
        }
        if ($endedAt->lt($contract->start_date)) {
            throw ValidationException::withMessages(['ended_at' => 'La date de fin ne peut pas précéder le début du contrat.']);
        }
        if ($data['end_reason'] === 'fin_periode_essai' && (! $contract->trial_end_date || $endedAt->gt($contract->trial_end_date))) {
            throw ValidationException::withMessages(['end_reason' => "La période d'essai est déjà terminée à cette date."]);
        }

        return DB::transaction(function () use ($contract, $data) {
            $contract->update([
                'ended_at' => $data['ended_at'],
                'end_reason' => $data['end_reason'],
                'end_notes' => $data['end_notes'] ?? null,
            ]);

            if (! empty($data['archive_employee']) && $contract->employee->status !== 'sorti') {
                $contract->employee->markAsLeft($data['ended_at'], Contract::END_REASONS[$data['end_reason']]);
            }

            return $contract;
        });
    }

    /**
     * Reporte le poste et le département d'un contrat ou avenant sur le dossier employé
     * une fois sa date d'effet atteinte (immédiatement, ou par la tâche planifiée contracts:apply-due).
     */
    public function applyIfDue(Contract $contract): bool
    {
        if ($contract->applied_at || $contract->start_date->isFuture() || $contract->ended_at) {
            return false;
        }

        $changes = array_filter([
            'position_id' => $contract->position_id,
            'department_id' => $contract->department_id,
        ]);

        DB::transaction(function () use ($contract, $changes) {
            if ($changes) {
                $contract->employee->update($changes);
            }
            $contract->update(['applied_at' => now()]);
        });

        return true;
    }

    public function applyAllDue(): int
    {
        $count = 0;
        Contract::query()
            ->with('employee')
            ->whereNull('applied_at')
            ->whereNull('ended_at')
            ->where('start_date', '<=', now()->toDateString())
            ->orderBy('start_date')
            ->orderBy('id')
            ->each(function (Contract $c) use (&$count) {
                $count += $this->applyIfDue($c) ? 1 : 0;
            });

        return $count;
    }

    /**
     * CTR-04 : fins de contrat et de période d'essai dans les $days prochains jours,
     * pour les employés présents de la requête fournie (périmètre de l'utilisateur).
     *
     * @return list<array{kind: string, date: CarbonImmutable, days_left: int, contract: Contract}>
     */
    public function upcomingDeadlines(Builder $employees, int $days): array
    {
        $today = CarbonImmutable::today();
        $limit = $today->addDays($days);

        $contracts = Contract::query()
            ->main()
            ->whereNull('ended_at')
            ->whereIn('employee_id', (clone $employees)->where('status', '!=', 'sorti')->select('id'))
            ->where(fn ($q) => $q->whereNotNull('end_date')->orWhereNotNull('trial_end_date'))
            ->with(['employee', 'amendments'])
            ->get();

        $deadlines = [];
        foreach ($contracts as $c) {
            $dates = [
                'fin_contrat' => $c->effectiveEndDate(),
                'fin_periode_essai' => $c->trial_end_date ? CarbonImmutable::parse($c->trial_end_date) : null,
            ];
            foreach ($dates as $kind => $date) {
                if ($date && $date->betweenIncluded($today, $limit)) {
                    $deadlines[] = ['kind' => $kind, 'date' => $date, 'days_left' => (int) $today->diffInDays($date), 'contract' => $c];
                }
            }
        }

        usort($deadlines, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $deadlines;
    }

    private function checkDates(array $data): void
    {
        $fixedTerm = in_array($data['type'], Contract::FIXED_TERM_TYPES, true);

        if ($fixedTerm && empty($data['end_date'])) {
            throw ValidationException::withMessages(['end_date' => 'La date de fin est obligatoire pour ce type de contrat.']);
        }
        if (! $fixedTerm && ! empty($data['end_date'])) {
            throw ValidationException::withMessages(['end_date' => 'Un CDI n’a pas de date de fin.']);
        }
        if (! empty($data['trial_end_date'])) {
            $trial = CarbonImmutable::parse($data['trial_end_date']);
            if ($trial->lt(CarbonImmutable::parse($data['start_date'])) || (! empty($data['end_date']) && $trial->gt(CarbonImmutable::parse($data['end_date'])))) {
                throw ValidationException::withMessages(['trial_end_date' => "La fin de la période d'essai doit être comprise dans la durée du contrat."]);
            }
        }
    }

    /** Un employé n'a qu'un seul contrat à la fois. */
    private function checkNoOverlap(Employee $employee, string $start, ?string $end, ?Contract $ignore = null): void
    {
        $newStart = CarbonImmutable::parse($start);
        $newEnd = $end ? CarbonImmutable::parse($end) : null;

        $overlap = $employee->contracts()
            ->with('amendments')
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get()
            ->first(function (Contract $c) use ($newStart, $newEnd) {
                $cEnd = $c->effectiveEndDate();

                return ($newEnd === null || $c->start_date->lte($newEnd)) && ($cEnd === null || $cEnd->gte($newStart));
            });

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => "Cette période chevauche le contrat {$overlap->reference}. Terminez-le d'abord.",
            ]);
        }
    }
}
