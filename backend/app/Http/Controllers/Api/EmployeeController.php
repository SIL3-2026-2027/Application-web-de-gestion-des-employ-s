<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\AccountService;
use App\Services\EmployeeExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private const SORTABLE = ['matricule', 'last_name', 'hire_date', 'status', 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $this->validateFilters($request, [
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $employees = $this->filteredQuery($request, $filters)
            ->with(['department', 'position', 'site', 'manager', 'currentContract.amendments'])
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /** EMP-07 / RAP-06 : export de la liste filtrée, dans le périmètre de l'utilisateur. */
    public function export(Request $request, EmployeeExportService $exporter)
    {
        $filters = $this->validateFilters($request, [
            'format' => ['required', Rule::in(['xlsx', 'csv', 'pdf'])],
        ]);
        activity('export')->causedBy($request->user())->withProperties($filters)->log('Export de la liste des employés');

        return $exporter->export($this->filteredQuery($request, $filters), $filters['format']);
    }

    private function validateFilters(Request $request, array $extra = []): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Employee::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            ...$extra,
        ]);
    }

    private function filteredQuery(Request $request, array $filters): Builder
    {
        return Employee::query()
            ->visibleTo($request->user())
            ->search($filters['search'] ?? null)
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['position_id'] ?? null, fn ($q, $v) => $q->where('position_id', $v))
            ->when($filters['site_id'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy($filters['sort'] ?? 'last_name', $filters['direction'] ?? 'asc')
            ->orderBy('first_name');
    }

    /** Liste courte pour les listes déroulantes (manager, etc.). */
    public function options(Request $request)
    {
        return Employee::query()
            ->visibleTo($request->user())
            ->where('status', '!=', 'sorti')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'matricule'])
            ->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->matricule})"]);
    }

    public function store(EmployeeRequest $request, AccountService $accounts): EmployeeResource
    {
        $data = $request->validated();
        $account = $data['account'] ?? [];

        // Dossier et compte de connexion créés ensemble, ou pas du tout
        $employee = DB::transaction(function () use ($request, $accounts, $data, $account) {
            $employee = Employee::create(Arr::except($data, 'account'));

            if (! empty($account['create'])) {
                $accounts->create($request->user(), [
                    'employee_id' => $employee->id,
                    'email' => $employee->work_email,
                    'password' => $account['password'],
                    'roles' => $account['roles'],
                    'must_change_password' => $account['must_change_password'] ?? true,
                ]);
            }

            return $employee;
        });

        return new EmployeeResource($employee->refresh()->load(['department', 'position', 'site', 'manager']));
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee->load(['department', 'position', 'site', 'manager']));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        return new EmployeeResource($employee->load(['department', 'position', 'site', 'manager']));
    }

    /** Départ d'un employé : le dossier est conservé, le compte est désactivé (EMP-05). */
    public function archive(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('delete', $employee);

        $data = $request->validate([
            'exit_date' => ['required', 'date', 'after_or_equal:'.$employee->hire_date->toDateString()],
            'exit_reason' => ['required', 'string', 'max:255'],
        ]);

        $employee->markAsLeft($data['exit_date'], $data['exit_reason']);

        return new EmployeeResource($employee->load(['department', 'position', 'site', 'manager']));
    }

    /** Historique des modifications du dossier (EMP-03). */
    public function history(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        return $employee->activities()
            ->with('causer:id,name')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'causer' => $a->causer?->name,
                'changes' => $a->properties,
                'created_at' => $a->created_at->toIso8601String(),
            ]);
    }
}
