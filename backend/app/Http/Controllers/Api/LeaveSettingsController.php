<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Services\Leave\BeninHolidays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Paramétrage des congés : types (CNG-01) et jours fériés (CNG-07). Écriture réservée aux RH. */
class LeaveSettingsController extends Controller
{
    public function types(Request $request)
    {
        return LeaveType::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    public function storeType(Request $request): LeaveType
    {
        return LeaveType::create($this->validateType($request));
    }

    public function updateType(Request $request, LeaveType $leaveType): LeaveType
    {
        $leaveType->update($this->validateType($request, $leaveType));

        return $leaveType;
    }

    public function destroyType(LeaveType $leaveType): JsonResponse
    {
        abort_if($leaveType->requests()->exists(), 422, 'Ce type est déjà utilisé : désactivez-le plutôt que de le supprimer.');
        $leaveType->delete();

        return response()->json(status: 204);
    }

    private function validateType(Request $request, ?LeaveType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', Rule::unique('leave_types')->ignore($type)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_deductible' => ['boolean'],
            'monthly_accrual' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'annual_allowance' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'requires_attachment' => ['boolean'],
            'requires_hr_approval' => ['boolean'],
            'employee_can_request' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    public function holidays(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        return PublicHoliday::query()->whereYear('date', $year)->orderBy('date')->get()
            ->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name]);
    }

    public function storeHoliday(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'unique:public_holidays,date'],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $holiday = PublicHoliday::create($data);

        return response()->json(['id' => $holiday->id, 'date' => $holiday->date->toDateString(), 'name' => $holiday->name], 201);
    }

    public function destroyHoliday(PublicHoliday $publicHoliday): JsonResponse
    {
        $publicHoliday->delete();

        return response()->json(status: 204);
    }

    /** Ajoute les jours fériés du Bénin d'une année (les dates déjà présentes sont conservées). */
    public function generateHolidays(Request $request)
    {
        $year = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']])['year'];

        $created = 0;
        foreach (BeninHolidays::forYear($year) as $date => $name) {
            $created += PublicHoliday::firstOrCreate(['date' => $date], ['name' => $name])->wasRecentlyCreated ? 1 : 0;
        }

        return ['created' => $created];
    }
}
