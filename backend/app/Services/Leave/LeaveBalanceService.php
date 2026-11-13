<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;

/**
 * Soldes de congés (CNG-04), calculés à la volée pour l'année civile :
 * restant = acquis + ajustements − pris (approuvés) − en attente.
 */
class LeaveBalanceService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function balances(Employee $employee, int $year): array
    {
        $types = LeaveType::query()->where('is_active', true)->where('is_deductible', true)->orderBy('name')->get();

        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereYear('start_date', $year)
            ->active()
            ->get(['leave_type_id', 'status', 'days']);

        $adjustments = LeaveAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->get(['leave_type_id', 'days']);

        return $types->map(function (LeaveType $type) use ($employee, $year, $requests, $adjustments) {
            $ofType = $requests->where('leave_type_id', $type->id);
            $accrued = $this->accrued($type, $employee, $year);
            $adjusted = (float) $adjustments->where('leave_type_id', $type->id)->sum('days');
            $taken = (float) $ofType->where('status', LeaveRequest::APPROVED)->sum('days');
            $pending = (float) $ofType->whereIn('status', LeaveRequest::AWAITING_STATUSES)->sum('days');

            return [
                'leave_type_id' => $type->id,
                'leave_type' => $type->name,
                'code' => $type->code,
                'color' => $type->color,
                'year' => $year,
                'accrued' => $accrued,
                'adjustments' => $adjusted,
                'taken' => $taken,
                'pending' => $pending,
                'remaining' => round($accrued + $adjusted - $taken - $pending, 2),
            ];
        })->values()->all();
    }

    public function remaining(Employee $employee, LeaveType $type, int $year): float
    {
        $row = collect($this->balances($employee, $year))->firstWhere('leave_type_id', $type->id);

        return $row['remaining'] ?? 0.0;
    }

    /**
     * Droits acquis à date : acquisition mensuelle pour chaque mois terminé travaillé
     * (ex. 2 j/mois), sinon forfait annuel. Plafonné aux droits annuels.
     */
    public function accrued(LeaveType $type, Employee $employee, int $year, ?CarbonImmutable $today = null): float
    {
        $today ??= CarbonImmutable::today();
        $yearStart = CarbonImmutable::create($year, 1, 1);
        $yearEnd = $yearStart->endOfYear()->startOfDay();
        $hired = CarbonImmutable::parse($employee->hire_date);

        if ($hired->gt($yearEnd) || $today->lt($yearStart)) {
            return 0;
        }

        if ($type->monthly_accrual) {
            $from = $hired->max($yearStart);
            $to = $today->min($yearEnd);
            $months = 0;
            for ($m = 1; $m <= 12; $m++) {
                $monthEnd = CarbonImmutable::create($year, $m, 1)->endOfMonth()->startOfDay();
                if ($monthEnd->gte($from) && $monthEnd->lte($to)) {
                    $months++;
                }
            }
            $value = $months * $type->monthly_accrual;

            return round($type->annual_allowance ? min($value, $type->annual_allowance) : $value, 2);
        }

        return (float) ($type->annual_allowance ?? 0);
    }
}
