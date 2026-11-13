<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Leave\FrenchHolidays;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $cp;
    private User $rh;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 09:00:00');

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->cp = LeaveType::create(['name' => 'Congés payés', 'code' => 'CP', 'is_deductible' => true, 'monthly_accrual' => 2.08, 'annual_allowance' => 25]);
        $this->rh = $this->userWithRole(User::ROLE_RH);
        $this->manager = $this->userWithRole(User::ROLE_MANAGER);
        $this->employee = $this->userWithRole(User::ROLE_EMPLOYE, ['manager_id' => $this->manager->employee->id, 'hire_date' => '2020-01-01']);
    }

    private function userWithRole(string $role, array $employee = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id, 'hire_date' => '2020-01-01', ...$employee]);

        return $user->fresh();
    }

    private function request(User $as, array $data = [])
    {
        return $this->actingAs($as)->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->cp->id,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-16',
            ...$data,
        ]);
    }

    // ---------- Calculs ----------

    public function test_easter_and_french_holidays(): void
    {
        $this->assertSame('2026-04-05', FrenchHolidays::easterSunday(2026)->toDateString());
        $this->assertSame('2027-03-28', FrenchHolidays::easterSunday(2027)->toDateString());

        $holidays = FrenchHolidays::forYear(2026);
        $this->assertCount(11, $holidays);
        $this->assertSame('Lundi de Pâques', $holidays['2026-04-06']);
        $this->assertSame('Ascension', $holidays['2026-05-14']);
        $this->assertSame('Lundi de Pentecôte', $holidays['2026-05-25']);
    }

    public function test_working_days_exclude_weekends_holidays_and_half_days(): void
    {
        PublicHoliday::create(['date' => '2026-11-11', 'name' => 'Armistice']);
        $calc = new WorkingDays;

        $this->assertSame(5.0, (float) $calc->count('2026-10-12', '2026-10-18'));      // lundi → dimanche
        $this->assertSame(4.0, (float) $calc->count('2026-11-09', '2026-11-13'));      // mercredi férié
        $this->assertSame(4.0, (float) $calc->count('2026-10-12', '2026-10-16', true, true));
        $this->assertSame(0.5, (float) $calc->count('2026-10-12', '2026-10-12', true));
        $this->assertSame(0.0, (float) $calc->count('2026-10-17', '2026-10-18'));      // week-end
    }

    public function test_accrual_is_monthly_and_capped(): void
    {
        $service = new LeaveBalanceService;
        $today = CarbonImmutable::parse('2026-10-01');

        $employee = Employee::factory()->make(['hire_date' => '2020-01-01']);
        $this->assertSame(18.72, $service->accrued($this->cp, $employee, 2026, $today)); // 9 mois terminés × 2,08
        $this->assertSame(24.96, $service->accrued($this->cp, $employee, 2025, $today)); // 12 × 2,08

        $newcomer = Employee::factory()->make(['hire_date' => '2026-06-15']);
        $this->assertSame(8.32, $service->accrued($this->cp, $newcomer, 2026, $today)); // juin → septembre

        $capped = LeaveType::make(['monthly_accrual' => 3, 'annual_allowance' => 25]);
        $this->assertSame(25.0, $service->accrued($capped, $employee, 2025, $today));
    }

    // ---------- Circuit de validation ----------

    public function test_employee_request_then_manager_approval(): void
    {
        $id = $this->request($this->employee)
            ->assertCreated()
            ->assertJsonPath('data.status', 'en_attente')
            ->assertJsonPath('data.days', 5)
            ->json('data.id');

        // La demande apparaît chez le manager et réserve le solde
        $this->actingAs($this->manager)->getJson('/api/v1/leave-requests?scope=to_approve')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can.decide', true);

        $this->actingAs($this->employee)->getJson("/api/v1/employees/{$this->employee->employee->id}/leave-balances")
            ->assertJsonPath('balances.0.pending', 5)
            ->assertJsonPath('balances.0.remaining', 13.72);

        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approuve');

        $this->actingAs($this->employee)->getJson("/api/v1/employees/{$this->employee->employee->id}/leave-balances")
            ->assertJsonPath('balances.0.taken', 5)
            ->assertJsonPath('balances.0.pending', 0);
    }

    public function test_two_step_approval_when_hr_validation_required(): void
    {
        $css = LeaveType::create(['name' => 'Sans solde', 'code' => 'CSS', 'is_deductible' => false, 'requires_hr_approval' => true]);
        $id = $this->request($this->employee, ['leave_type_id' => $css->id])->json('data.id');

        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve")
            ->assertJsonPath('data.status', 'valide_manager');

        // Le manager ne peut plus agir, les RH oui
        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve")->assertForbidden();
        $this->actingAs($this->rh)->postJson("/api/v1/leave-requests/{$id}/approve")
            ->assertJsonPath('data.status', 'approuve');
    }

    public function test_rejection_requires_a_reason(): void
    {
        $id = $this->request($this->employee)->json('data.id');

        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/reject")
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/reject", ['reason' => 'Période chargée'])
            ->assertJsonPath('data.status', 'refuse')
            ->assertJsonPath('data.refusal_reason', 'Période chargée');
    }

    public function test_insufficient_balance_is_refused(): void
    {
        $this->request($this->employee, ['start_date' => '2026-10-12', 'end_date' => '2026-11-13'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type_id');
    }

    public function test_overlapping_requests_are_refused(): void
    {
        $this->request($this->employee)->assertCreated();
        $this->request($this->employee, ['start_date' => '2026-10-15', 'end_date' => '2026-10-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_nobody_approves_own_request_or_other_teams(): void
    {
        $id = $this->request($this->manager)->json('data.id');
        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve")->assertForbidden();

        $otherManager = $this->userWithRole(User::ROLE_MANAGER);
        $employeeRequest = $this->request($this->employee)->json('data.id');
        $this->actingAs($otherManager)->postJson("/api/v1/leave-requests/{$employeeRequest}/approve")->assertForbidden();
        $this->actingAs($otherManager)->getJson("/api/v1/leave-requests/{$employeeRequest}")->assertForbidden();
    }

    public function test_employee_can_cancel_only_while_pending(): void
    {
        $id = $this->request($this->employee)->json('data.id');
        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve");

        $this->actingAs($this->employee)->postJson("/api/v1/leave-requests/{$id}/cancel")->assertForbidden();
        $this->actingAs($this->rh)->postJson("/api/v1/leave-requests/{$id}/cancel")->assertJsonPath('data.status', 'annule');
    }

    // ---------- Saisie RH ----------

    public function test_hr_declares_sick_leave_approved_immediately(): void
    {
        $sick = LeaveType::create(['name' => 'Maladie', 'code' => 'MAL', 'is_deductible' => false, 'requires_attachment' => true, 'employee_can_request' => false]);

        $this->request($this->employee, ['leave_type_id' => $sick->id])->assertStatus(422);

        $this->request($this->rh, ['leave_type_id' => $sick->id, 'employee_id' => $this->employee->employee->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approuve');
    }

    public function test_employee_cannot_request_for_someone_else(): void
    {
        $this->request($this->employee, ['employee_id' => $this->manager->employee->id])->assertForbidden();
    }

    public function test_attachment_required_and_downloadable(): void
    {
        Storage::fake('local');
        $evf = LeaveType::create(['name' => 'Événement familial', 'code' => 'EVF', 'is_deductible' => false, 'requires_attachment' => true]);

        $this->request($this->employee, ['leave_type_id' => $evf->id])->assertJsonValidationErrors('attachment');

        $id = $this->actingAs($this->employee)->post('/api/v1/leave-requests', [
            'leave_type_id' => $evf->id,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'start_half_day' => '0',
            'end_half_day' => '0',
            'attachment' => UploadedFile::fake()->create('acte.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_attachment', true)->json('data.id');

        $this->actingAs($this->manager)->get("/api/v1/leave-requests/{$id}/attachment")->assertOk();
        $this->actingAs($this->userWithRole(User::ROLE_EMPLOYE))->get("/api/v1/leave-requests/{$id}/attachment")->assertForbidden();
    }

    public function test_hr_adjustment_changes_balance(): void
    {
        $employeeId = $this->employee->employee->id;

        $this->actingAs($this->manager)->postJson("/api/v1/employees/{$employeeId}/leave-adjustments", [
            'leave_type_id' => $this->cp->id, 'year' => 2026, 'days' => 2, 'reason' => 'Report',
        ])->assertForbidden();

        $this->actingAs($this->rh)->postJson("/api/v1/employees/{$employeeId}/leave-adjustments", [
            'leave_type_id' => $this->cp->id, 'year' => 2026, 'days' => 3.5, 'reason' => 'Report N-1',
        ])->assertCreated()->assertJsonPath('0.remaining', 22.22);
    }

    public function test_calendar_is_limited_to_visible_team(): void
    {
        $this->request($this->employee)->assertCreated();
        $outsider = $this->userWithRole(User::ROLE_EMPLOYE);
        $this->request($outsider)->assertCreated();

        $this->actingAs($this->manager)->getJson('/api/v1/leave-calendar?month=2026-10')
            ->assertOk()
            ->assertJsonCount(2, 'employees') // le manager + son équipier
            ->assertJsonCount(1, 'leaves');

        $this->actingAs($this->rh)->getJson('/api/v1/leave-calendar?month=2026-10')
            ->assertJsonCount(2, 'leaves');
    }

    public function test_only_hr_manages_leave_settings(): void
    {
        $this->actingAs($this->employee)->postJson('/api/v1/public-holidays/generate', ['year' => 2027])->assertForbidden();
        $this->actingAs($this->rh)->postJson('/api/v1/public-holidays/generate', ['year' => 2027])
            ->assertOk()->assertJsonPath('created', 11);
        $this->actingAs($this->rh)->postJson('/api/v1/public-holidays/generate', ['year' => 2027])
            ->assertJsonPath('created', 0);
    }
}
