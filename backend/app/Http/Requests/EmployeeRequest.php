<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee
            ? $this->user()->can('update', $employee)
            : $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $creatingAccount = ! $employee && $this->boolean('account.create');

        return [
            'civility' => ['nullable', Rule::in(['M.', 'Mme'])],
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:-16 years'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'social_security_number' => ['nullable', 'string', 'max:30'],
            'marital_status' => ['nullable', Rule::in(['celibataire', 'marie', 'pacse', 'divorce', 'veuf'])],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:30'],

            'personal_email' => ['nullable', 'email', 'max:150'],
            // L'e-mail professionnel sert d'identifiant de connexion quand on crée le compte
            'work_email' => $creatingAccount
                ? ['required', 'email', 'max:150', Rule::unique('employees', 'work_email'), Rule::unique('users', 'email')]
                : ['nullable', 'email', 'max:150', Rule::unique('employees', 'work_email')->ignore($employee)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34'],

            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'work_schedule_id' => ['nullable', 'exists:work_schedules,id'],
            'manager_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee?->id])],
            'hire_date' => ['required', 'date'],
            'status' => ['required', Rule::in(Employee::STATUSES)],
            'exit_date' => ['nullable', 'date', 'after_or_equal:hire_date', 'required_if:status,sorti'],
            'exit_reason' => ['nullable', 'string', 'max:255', 'required_if:status,sorti'],

            // ADM-01 : création du compte de connexion en même temps que le dossier (à la création seulement)
            'account' => ['nullable', 'array', Rule::prohibitedIf($employee !== null)],
            'account.create' => ['boolean'],
            'account.password' => ['required_if_accepted:account.create', 'nullable', 'confirmed', \App\Http\Controllers\Api\PasswordController::rule()],
            'account.roles' => ['required_if_accepted:account.create', 'nullable', 'array', 'min:1'],
            'account.roles.*' => [Rule::in(\App\Models\User::ROLES)],
            'account.must_change_password' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'last_name' => 'nom',
            'first_name' => 'prénom',
            'birth_date' => 'date de naissance',
            'work_email' => 'e-mail professionnel',
            'hire_date' => "date d'embauche",
            'exit_date' => 'date de sortie',
            'exit_reason' => 'motif de sortie',
            'manager_id' => 'manager',
            'account.password' => 'mot de passe',
            'account.roles' => 'rôles',
        ];
    }
}
