<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    private User $rh;
    private User $manager;
    private User $employee;
    private Position $dev;
    private Position $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 09:00:00');

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->dev = Position::create(['title' => 'Développeur']);
        $this->lead = Position::create(['title' => 'Chef de projet']);
        $this->rh = $this->userWithRole(User::ROLE_RH);
        $this->manager = $this->userWithRole(User::ROLE_MANAGER);
        $this->employee = $this->userWithRole(User::ROLE_EMPLOYE, ['manager_id' => $this->manager->employee->id, 'position_id' => $this->dev->id]);
    }

    private function userWithRole(string $role, array $employee = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id, 'hire_date' => '2024-01-01', ...$employee]);

        return $user->fresh();
    }

    private function createContract(array $data = [])
    {
        return $this->actingAs($this->rh)->postJson("/api/v1/employees/{$this->employee->employee->id}/contracts", [
            'type' => 'CDI',
            'start_date' => '2024-01-01',
            'trial_end_date' => '2024-03-31',
            'weekly_hours' => 35,
            'base_salary' => 42000,
            'position_id' => $this->dev->id,
            ...$data,
        ]);
    }

    public function test_hr_creates_contract_with_reference(): void
    {
        $this->createContract()
            ->assertCreated()
            ->assertJsonPath('data.reference', 'CTR-2026-0001')
            ->assertJsonPath('data.status', 'en_cours')
            ->assertJsonPath('data.base_salary', 42000);

        $this->createContract(['start_date' => '2030-01-01', 'trial_end_date' => null])->assertJsonValidationErrors('start_date'); // chevauchement avec le CDI
    }

    public function test_fixed_term_rules(): void
    {
        $this->createContract(['type' => 'CDD'])->assertJsonValidationErrors('end_date');
        $this->createContract(['type' => 'CDI', 'end_date' => '2025-01-01'])->assertJsonValidationErrors('end_date');
        $this->createContract(['type' => 'CDD', 'end_date' => '2024-06-30', 'trial_end_date' => '2024-08-01'])->assertJsonValidationErrors('trial_end_date');
        $this->createContract(['type' => 'CDD', 'end_date' => '2024-06-30', 'trial_end_date' => '2024-01-15'])->assertCreated();
    }

    public function test_only_hr_creates_contracts(): void
    {
        $this->actingAs($this->manager)->postJson("/api/v1/employees/{$this->employee->employee->id}/contracts", [
            'type' => 'CDI', 'start_date' => '2024-01-01', 'weekly_hours' => 35, 'base_salary' => 1,
        ])->assertForbidden();
    }

    public function test_salary_hidden_from_manager_but_visible_to_employee(): void
    {
        $this->createContract();
        $url = "/api/v1/employees/{$this->employee->employee->id}/contracts";

        $this->actingAs($this->manager)->getJson($url)->assertOk()->assertJsonMissingPath('data.0.base_salary');
        $this->actingAs($this->employee)->getJson($url)->assertOk()->assertJsonPath('data.0.base_salary', 42000);
        $this->actingAs($this->userWithRole(User::ROLE_EMPLOYE))->getJson($url)->assertForbidden();
    }

    public function test_amendment_changes_terms_and_applies_position_when_effective(): void
    {
        $id = $this->createContract()->json('data.id');

        // Avenant immédiat : promotion + augmentation
        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", [
            'start_date' => '2026-09-01',
            'base_salary' => 48000,
            'position_id' => $this->lead->id,
            'description' => 'Promotion',
        ])->assertOk()
            ->assertJsonCount(1, 'data.amendments')
            ->assertJsonPath('data.amendments.0.reference', 'AV-2026-0001')
            ->assertJsonPath('data.current_terms.base_salary', 48000)
            ->assertJsonPath('data.current_terms.position', 'Chef de projet');

        $this->assertSame($this->lead->id, $this->employee->employee->fresh()->position_id);

        // Avenant futur : appliqué seulement par la tâche planifiée à sa date d'effet
        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", [
            'start_date' => '2026-12-01',
            'position_id' => $this->dev->id,
        ])->assertJsonPath('data.current_terms.position', 'Chef de projet');

        $this->artisan('contracts:apply-due')->assertSuccessful();
        $this->assertSame($this->lead->id, $this->employee->employee->fresh()->position_id);

        $this->travelTo('2026-12-01 01:00:00');
        $this->artisan('contracts:apply-due')->expectsOutputToContain('1 contrat(s)');
        $this->assertSame($this->dev->id, $this->employee->employee->fresh()->position_id);
    }

    public function test_amendment_requires_a_change_and_valid_date(): void
    {
        $id = $this->createContract()->json('data.id');

        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", ['start_date' => '2026-09-01'])
            ->assertJsonValidationErrors('description');
        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", ['start_date' => '2023-01-01', 'weekly_hours' => 30])
            ->assertJsonValidationErrors('start_date');
        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", ['start_date' => '2026-09-01', 'end_date' => '2027-01-01'])
            ->assertJsonValidationErrors('end_date'); // un CDI n'a pas de fin
    }

    public function test_cdd_extension_moves_end_date(): void
    {
        $id = $this->createContract(['type' => 'CDD', 'start_date' => '2026-04-01', 'end_date' => '2026-10-15', 'trial_end_date' => null])->json('data.id');

        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", ['start_date' => '2026-10-01', 'end_date' => '2027-03-31'])
            ->assertJsonPath('data.end_date', '2026-10-15')
            ->assertJsonPath('data.effective_end_date', '2027-03-31');
    }

    public function test_ending_contract_can_archive_employee(): void
    {
        $id = $this->createContract()->json('data.id');

        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/end", ['ended_at' => '2026-09-30', 'end_reason' => 'fin_periode_essai'])
            ->assertJsonValidationErrors('end_reason'); // essai terminé depuis longtemps

        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/end", [
            'ended_at' => '2026-09-30',
            'end_reason' => 'demission',
            'archive_employee' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'termine')
            ->assertJsonPath('data.end_reason_label', 'Démission');

        $employee = $this->employee->employee->fresh();
        $this->assertSame('sorti', $employee->status);
        $this->assertSame('Démission', $employee->exit_reason);
        $this->assertFalse($this->employee->fresh()->is_active);

        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/amendments", ['start_date' => '2026-09-01', 'weekly_hours' => 30])
            ->assertJsonValidationErrors('start_date');
    }

    public function test_expiring_contracts_and_trial_periods(): void
    {
        $this->createContract(['type' => 'CDD', 'start_date' => '2026-04-01', 'end_date' => '2026-10-20', 'trial_end_date' => null]);
        $other = Employee::factory()->create(['manager_id' => $this->manager->employee->id]);
        Contract::create(['employee_id' => $other->id, 'type' => 'CDI', 'start_date' => '2026-08-01', 'trial_end_date' => '2026-10-31', 'weekly_hours' => 35, 'base_salary' => 30000]);
        $outsider = Employee::factory()->create();
        Contract::create(['employee_id' => $outsider->id, 'type' => 'CDD', 'start_date' => '2026-01-01', 'end_date' => '2026-10-05', 'weekly_hours' => 35, 'base_salary' => 30000]);

        $this->actingAs($this->rh)->getJson('/api/v1/contracts/expiring?days=30')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.employee.id', $outsider->id)
            ->assertJsonPath('0.days_left', 4)
            ->assertJsonPath('1.kind', 'fin_contrat')
            ->assertJsonPath('2.kind', 'fin_periode_essai');

        // Le manager ne voit que son équipe
        $this->actingAs($this->manager)->getJson('/api/v1/contracts/expiring?days=30')->assertJsonCount(2);
    }

    public function test_pdf_documents(): void
    {
        $id = $this->createContract()->json('data.id');

        $this->actingAs($this->employee)->get("/api/v1/contracts/{$id}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->manager)->get("/api/v1/contracts/{$id}/pdf")->assertForbidden();

        $this->actingAs($this->rh)->getJson("/api/v1/contracts/{$id}/certificate")->assertStatus(422);
        $this->actingAs($this->rh)->postJson("/api/v1/contracts/{$id}/end", ['ended_at' => '2026-09-30', 'end_reason' => 'demission']);
        $this->actingAs($this->rh)->get("/api/v1/contracts/{$id}/certificate")->assertOk()->assertHeader('content-type', 'application/pdf');

        $amendmentId = Contract::create(['employee_id' => $this->employee->employee->id, 'parent_id' => $id, 'kind' => 'avenant', 'type' => 'CDI', 'start_date' => '2025-01-01', 'base_salary' => 45000])->id;
        $this->actingAs($this->rh)->get("/api/v1/contracts/{$amendmentId}/pdf")->assertOk();
    }

    public function test_signed_document_upload(): void
    {
        Storage::fake('local');
        $id = $this->createContract()->json('data.id');

        $this->actingAs($this->rh)->post("/api/v1/contracts/{$id}/document", [
            'document' => UploadedFile::fake()->create('signe.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_document', true);

        $this->actingAs($this->employee)->get("/api/v1/contracts/{$id}/document")->assertOk();
        $this->actingAs($this->manager)->get("/api/v1/contracts/{$id}/document")->assertForbidden();
    }

    public function test_employee_list_shows_current_contract(): void
    {
        $this->createContract(['type' => 'CDD', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'trial_end_date' => null]);

        $this->actingAs($this->rh)->getJson("/api/v1/employees?search={$this->employee->employee->matricule}")
            ->assertJsonPath('data.0.contract.type', 'CDD')
            ->assertJsonPath('data.0.contract.end_date', '2026-12-31');
    }
}
