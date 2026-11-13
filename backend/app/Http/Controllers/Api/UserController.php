<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** ADM-01 : gestion des comptes par l'administration (administrateur et RH, middleware de route). */
class UserController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['actif', 'desactive'])],
        ]);

        $users = User::query()
            ->with(['roles', 'employee'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'actif'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return UserResource::collection($users)->additional([
            'assignable_roles' => $this->accounts->assignableRoles($request->user()),
        ]);
    }

    /** Employés présents sans compte, pour le formulaire de création. */
    public function employeesWithoutAccount()
    {
        return Employee::query()
            ->whereNull('user_id')
            ->where('status', '!=', 'sorti')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'matricule', 'work_email'])
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'label' => "{$e->full_name} ({$e->matricule})",
                'work_email' => $e->work_email,
            ]);
    }

    public function store(Request $request): UserResource
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'name' => ['required_without:employee_id', 'nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordController::rule()],
            'must_change_password' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(User::ROLES)],
        ], [
            'email.unique' => 'Un compte existe déjà avec cette adresse e-mail.',
            'roles.required' => 'Choisissez au moins un rôle.',
        ], ['name' => 'nom', 'roles' => 'rôles', 'password' => 'mot de passe']);

        $user = $this->accounts->create($request->user(), $data);

        return new UserResource($user->load(['roles', 'employee']));
    }

    public function update(Request $request, User $user): UserResource
    {
        abort_unless($this->accounts->canManage($request->user(), $user), 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(User::ROLES)],
        ], ['email.unique' => 'Un compte existe déjà avec cette adresse e-mail.', 'roles.required' => 'Choisissez au moins un rôle.']);

        $this->accounts->update($request->user(), $user, $data['roles'], $data['email']);

        return new UserResource($user->fresh()->load(['roles', 'employee']));
    }

    public function activate(Request $request, User $user): UserResource
    {
        return $this->setActive($request, $user, true);
    }

    public function deactivate(Request $request, User $user): UserResource
    {
        return $this->setActive($request, $user, false);
    }

    /** Nouveau mot de passe saisi par l'administration. */
    public function setPassword(Request $request, User $user): UserResource
    {
        abort_unless($this->accounts->canManage($request->user(), $user), 403);

        $data = $request->validate([
            'password' => ['required', 'confirmed', PasswordController::rule()],
            'must_change_password' => ['boolean'],
        ], [], ['password' => 'mot de passe']);

        $this->accounts->setPassword($request->user(), $user, $data['password'], $request->boolean('must_change_password', true));

        return new UserResource($user->fresh()->load(['roles', 'employee']));
    }

    private function setActive(Request $request, User $user, bool $active): UserResource
    {
        abort_unless($this->accounts->canManage($request->user(), $user), 403);
        $this->accounts->setActive($request->user(), $user, $active);

        return new UserResource($user->fresh()->load(['roles', 'employee']));
    }
}
