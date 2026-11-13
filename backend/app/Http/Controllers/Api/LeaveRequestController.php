<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Leave\LeaveRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveRequestController extends Controller
{
    private const RELATIONS = ['employee.user:id,avatar_path', 'leaveType', 'creator', 'managerDecider', 'hrDecider'];

    public function __construct(private readonly LeaveRequestService $service) {}

    /**
     * scope = mine (mes demandes) | to_approve (à valider par moi) | all (périmètre visible)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['mine', 'to_approve', 'all'])],
            'status' => ['nullable', Rule::in([LeaveRequest::PENDING, LeaveRequest::MANAGER_APPROVED, LeaveRequest::APPROVED, LeaveRequest::REFUSED, LeaveRequest::CANCELLED])],
            'employee_id' => ['nullable', 'integer'],
            'leave_type_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);
        $user = $request->user();
        $ownId = $user->employee?->id;

        $query = LeaveRequest::query()->with(self::RELATIONS);

        match ($filters['scope'] ?? 'all') {
            'mine' => $query->where('employee_id', $ownId ?? 0),
            'to_approve' => $user->isHr()
                ? $query->whereIn('status', LeaveRequest::AWAITING_STATUSES)->where('employee_id', '!=', $ownId ?? 0)
                : $query->where('status', LeaveRequest::PENDING)
                    ->whereIn('employee_id', Employee::query()->where('manager_id', $ownId ?? 0)->select('id')),
            default => $query->visibleTo($user),
        };

        $requests = $query
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['leave_type_id'] ?? null, fn ($q, $v) => $q->where('leave_type_id', $v))
            ->when($filters['year'] ?? null, fn ($q, $v) => $q->whereYear('start_date', $v))
            ->orderByDesc('start_date')
            ->paginate(20)
            ->withQueryString();

        return LeaveRequestResource::collection($requests);
    }

    /** Aperçu : nombre de jours ouvrés, solde restant et erreurs éventuelles, sans enregistrer. */
    public function preview(Request $request)
    {
        [$employee, $type, $data] = $this->validated($request, forPreview: true);

        return $this->service->evaluate($employee, $type, $data, $request->user());
    }

    public function store(Request $request): LeaveRequestResource
    {
        [$employee, $type, $data] = $this->validated($request);

        $leave = $this->service->create($request->user(), $employee, $type, $data, $request->file('attachment'));

        return new LeaveRequestResource($leave->load(self::RELATIONS));
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('view', $leaveRequest);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('decide', $leaveRequest);

        return new LeaveRequestResource($this->service->approve($request->user(), $leaveRequest)->load(self::RELATIONS));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('decide', $leaveRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motif du refus']);

        return new LeaveRequestResource($this->service->reject($request->user(), $leaveRequest, $data['reason'])->load(self::RELATIONS));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('cancel', $leaveRequest);

        return new LeaveRequestResource($this->service->cancel($request->user(), $leaveRequest)->load(self::RELATIONS));
    }

    public function attachment(LeaveRequest $leaveRequest): StreamedResponse
    {
        $this->authorize('view', $leaveRequest);
        abort_unless($leaveRequest->attachment_path && Storage::disk('local')->exists($leaveRequest->attachment_path), 404);

        return Storage::disk('local')->download($leaveRequest->attachment_path);
    }

    /** Calendrier d'équipe (CNG-05) : absences du mois pour le périmètre visible. */
    public function calendar(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
        ]);
        $start = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        $employees = Employee::query()
            ->visibleTo($request->user())
            ->where('status', '!=', 'sorti')
            ->when($data['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->with('user:id,avatar_path')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'department_id', 'user_id', 'photo_path']);

        $leaves = LeaveRequest::query()
            ->with('leaveType:id,name,color,code')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->active()
            ->overlapping($start->toDateString(), $end->toDateString())
            ->get();

        return [
            'month' => $data['month'],
            'employees' => $employees->map(fn (Employee $e) => ['id' => $e->id, 'full_name' => $e->full_name, 'avatar_url' => $e->avatarUrl()]),
            'holidays' => PublicHoliday::query()
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get()
                ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name]),
            'leaves' => $leaves->map(fn (LeaveRequest $l) => [
                'id' => $l->id,
                'employee_id' => $l->employee_id,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'start_half_day' => $l->start_half_day,
                'end_half_day' => $l->end_half_day,
                'status' => $l->status,
                'leave_type' => ['name' => $l->leaveType->name, 'code' => $l->leaveType->code, 'color' => $l->leaveType->color],
            ]),
        ];
    }

    /**
     * Valide la saisie et résout l'employé concerné et le type de congé.
     *
     * @return array{0: Employee, 1: LeaveType, 2: array}
     */
    private function validated(Request $request, bool $forPreview = false): array
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_half_day' => ['boolean'],
            'end_half_day' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [], [
            'leave_type_id' => 'type de congé',
            'start_date' => 'date de début',
            'end_date' => 'date de fin',
            'attachment' => 'justificatif',
        ]);

        $employeeId = (int) ($data['employee_id'] ?? $user->employee?->id);
        abort_unless($employeeId, 422, "Votre compte n'est rattaché à aucun dossier employé.");

        // Saisir pour quelqu'un d'autre est réservé aux RH (CNG-06)
        abort_if($employeeId !== $user->employee?->id && ! $user->isHr(), 403);

        $employee = Employee::findOrFail($employeeId);
        $type = LeaveType::findOrFail($data['leave_type_id']);

        if (! $user->isHr() && ! $type->employee_can_request) {
            abort(422, 'Ce type d\'absence est déclaré par les ressources humaines.');
        }

        if ($data['start_date'] === $data['end_date'] && ($data['start_half_day'] ?? false) && ($data['end_half_day'] ?? false)) {
            abort(422, 'Pour une demi-journée sur un seul jour, cochez une seule des deux options.');
        }

        if (! $forPreview && $type->requires_attachment && ! $user->isHr() && ! $request->hasFile('attachment')) {
            throw ValidationException::withMessages(['attachment' => 'Un justificatif est obligatoire pour ce type de congé.']);
        }

        return [$employee, $type, $data];
    }
}
