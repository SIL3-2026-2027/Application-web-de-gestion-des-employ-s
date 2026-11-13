<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Soldes de congés et ajustements manuels (CNG-04). */
class LeaveBalanceController extends Controller
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function index(Request $request, Employee $employee)
    {
        $this->authorize('view', $employee);
        $year = (int) $request->query('year', now()->year);

        return [
            'year' => $year,
            'balances' => $this->balances->balances($employee, $year),
            'adjustments' => $request->user()->isHr()
                ? $employee->leaveAdjustments()->with(['leaveType:id,name', 'author:id,name'])->where('year', $year)->latest('id')->get()
                    ->map(fn (LeaveAdjustment $a) => [
                        'id' => $a->id,
                        'leave_type' => $a->leaveType->name,
                        'days' => $a->days,
                        'reason' => $a->reason,
                        'author' => $a->author?->name,
                        'created_at' => $a->created_at->toIso8601String(),
                    ])
                : [],
        ];
    }

    public function adjust(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('is_deductible', true)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'days' => ['required', 'numeric', 'not_in:0', 'between:-100,100', 'multiple_of:0.5'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['days' => 'nombre de jours', 'reason' => 'justification']);

        $employee->leaveAdjustments()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json($this->balances->balances($employee, $data['year']), 201);
    }
}
