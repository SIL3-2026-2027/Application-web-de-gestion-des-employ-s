<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function userWithRole(string $role, array $employee = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id, ...$employee]);

        return $user->fresh();
    }

    public function test_hr_can_create_employee_with_generated_matricule(): void
    {
        $rh = $this->userWithRole(User::ROLE_RH);
        $department = Department::create(['name' => 'IT', 'code' => 'IT']);

        $response = $this->actingAs($rh)->postJson('/api/v1/employees', [
            'last_name' => 'Durand',
            'first_name' => 'Lucas',
            'hire_date' => '2026-01-05',
            'status' => 'periode_essai',
            'department_id' => $department->id,
            'iban' => 'FR7630006000011234567890189',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.full_name', 'Lucas Durand')
            ->assertJsonPath('data.department.name', 'IT')
            ->assertJsonPath('data.iban', 'FR7630006000011234567890189');

        $this->assertMatchesRegularExpression('/^EMP\d{5}$/', $response->json('data.matricule'));

        // L'IBAN est chiffré en base
        $raw = \DB::table('employees')->where('id', $response->json('data.id'))->value('iban');
        $this->assertNotSame('FR7630006000011234567890189', $raw);
    }

    public function test_validation_errors_are_returned(): void
    {
        $rh = $this->userWithRole(User::ROLE_RH);

        $this->actingAs($rh)->postJson('/api/v1/employees', ['status' => 'sorti'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_name', 'first_name', 'hire_date', 'exit_date', 'exit_reason']);
    }

    public function test_employee_cannot_create_or_update(): void
    {
        $user = $this->userWithRole(User::ROLE_EMPLOYE);
        $other = Employee::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/employees', [
            'last_name' => 'X', 'first_name' => 'Y', 'hire_date' => '2026-01-01', 'status' => 'actif',
        ])->assertForbidden();

        $this->actingAs($user)->putJson("/api/v1/employees/{$other->id}", [
            'last_name' => 'X', 'first_name' => 'Y', 'hire_date' => '2026-01-01', 'status' => 'actif',
        ])->assertForbidden();
    }

    public function test_employee_only_sees_own_record(): void
    {
        $user = $this->userWithRole(User::ROLE_EMPLOYE);
        $other = Employee::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $user->employee->id);

        $this->actingAs($user)->getJson("/api/v1/employees/{$other->id}")->assertForbidden();
    }

    public function test_manager_sees_own_team_only_and_no_sensitive_data(): void
    {
        $manager = $this->userWithRole(User::ROLE_MANAGER);
        $member = Employee::factory()->create(['manager_id' => $manager->employee->id, 'iban' => 'FR76123']);
        Employee::factory()->create();

        $this->actingAs($manager)->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($manager)->getJson("/api/v1/employees/{$member->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.iban');
    }

    public function test_list_can_be_searched_and_filtered(): void
    {
        $rh = $this->userWithRole(User::ROLE_RH, ['last_name' => 'Bernard', 'status' => 'actif']);
        Employee::factory()->create(['last_name' => 'Zidane', 'status' => 'actif']);
        Employee::factory()->create(['last_name' => 'Zola', 'status' => 'periode_essai']);

        $this->actingAs($rh)->getJson('/api/v1/employees?search=Zidane')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Zidane');

        $this->actingAs($rh)->getJson('/api/v1/employees?status=periode_essai')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Zola');
    }

    public function test_archiving_keeps_record_and_disables_account(): void
    {
        $rh = $this->userWithRole(User::ROLE_RH);
        $leaver = $this->userWithRole(User::ROLE_EMPLOYE, ['hire_date' => '2020-01-01']);

        $this->actingAs($rh)->postJson("/api/v1/employees/{$leaver->employee->id}/archive", [
            'exit_date' => '2026-09-30',
            'exit_reason' => 'Démission',
        ])->assertOk()->assertJsonPath('data.status', 'sorti');

        $this->assertDatabaseHas('employees', ['id' => $leaver->employee->id, 'status' => 'sorti']);
        $this->assertFalse($leaver->fresh()->is_active);
    }

    public function test_changes_are_logged_in_history(): void
    {
        $rh = $this->userWithRole(User::ROLE_RH);
        $employee = Employee::factory()->create(['last_name' => 'Avant', 'hire_date' => '2020-01-01', 'status' => 'actif']);

        $this->actingAs($rh)->putJson("/api/v1/employees/{$employee->id}", [
            'last_name' => 'Apres', 'first_name' => $employee->first_name, 'hire_date' => '2020-01-01', 'status' => 'actif',
        ])->assertOk();

        $this->actingAs($rh)->getJson("/api/v1/employees/{$employee->id}/history")
            ->assertOk()
            ->assertJsonPath('0.event', 'updated')
            ->assertJsonPath('0.changes.attributes.last_name', 'Apres')
            ->assertJsonPath('0.changes.old.last_name', 'Avant');
    }

    public function test_only_hr_can_manage_departments(): void
    {
        $manager = $this->userWithRole(User::ROLE_MANAGER);
        $rh = $this->userWithRole(User::ROLE_RH);

        $this->actingAs($manager)->postJson('/api/v1/departments', ['name' => 'Ventes', 'code' => 'VEN'])->assertForbidden();
        $this->actingAs($rh)->postJson('/api/v1/departments', ['name' => 'Ventes', 'code' => 'VEN'])->assertCreated();
        $this->actingAs($manager)->getJson('/api/v1/departments')->assertOk()->assertJsonCount(1);
    }
}
