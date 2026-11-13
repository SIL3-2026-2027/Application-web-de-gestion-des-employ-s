<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRubric;
use App\Models\RecurringPayItem;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\SimpleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $rh;
    private Employee $employee;
    private array $rubrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->rh = User::factory()->create();
        $this->rh->assignRole(User::ROLE_RH);

        $user = User::factory()->create();
        $user->assignRole(User::ROLE_EMPLOYE);
        $this->employee = Employee::factory()->create(['user_id' => $user->id, 'matricule' => 'EMP00042', 'hire_date' => '2024-01-01', 'status' => 'actif']);
        Contract::create(['employee_id' => $this->employee->id, 'type' => 'CDI', 'start_date' => '2024-01-01', 'weekly_hours' => 40, 'base_salary' => 3000]);

        WorkSchedule::create([
            'name' => 'Standard', 'is_default' => true, 'late_tolerance' => 10,
            'days' => collect(range(1, 5))->mapWithKeys(fn ($d) => [$d => ['start' => '09:00', 'end' => '17:00', 'break' => 60]])->all(),
        ]);

        foreach ([
            ['PRIME_EXC', 'gain', 'montant', 'P100'], ['IND_TRANSPORT', 'gain', 'montant', 'P200'], ['ACOMPTE', 'retenue', 'montant', 'R100'],
            ['HS12', 'gain', 'heures', 'H112'], ['HS35', 'gain', 'heures', 'H135'], ['ABS_NJ', 'retenue', 'jours', 'A900'], ['CP', 'information', 'jours', 'C100'],
        ] as [$code, $kind, $unit, $export]) {
            $this->rubrics[$code] = PayrollRubric::create(['code' => $code, 'label' => $code, 'kind' => $kind, 'unit' => $unit, 'export_code' => $export]);
        }
    }

    private function overtime(string $date, int $minutes, string $status = 'approuve'): void
    {
        OvertimeRequest::create(['employee_id' => $this->employee->id, 'date' => $date, 'minutes' => $minutes, 'reason' => 'x', 'status' => $status]);
    }

    private function items(string $month = '2026-10')
    {
        return collect($this->actingAs($this->rh)->getJson("/api/v1/payroll/periods/{$month}")->assertOk()->json('employees.0.items'))
            ->keyBy('rubric.code');
    }

    public function test_only_hr_accesses_payroll(): void
    {
        $this->actingAs($this->employee->user)->getJson('/api/v1/payroll/periods/2026-10')->assertForbidden();
        $this->actingAs($this->rh)->getJson('/api/v1/payroll/periods/2026-13')->assertNotFound();
    }

    public function test_overtime_split_12_35_by_week_including_week_across_months(): void
    {
        // Semaine du 28/09 au 04/10 : 6 h en septembre, puis 4 h le 01/10 → 2 h à 12 %, 2 h à 35 % en octobre
        $this->overtime('2026-09-29', 360);
        $this->overtime('2026-10-01', 240);
        // Semaine du 12/10 : 9 h → 8 h à 12 %, 1 h à 35 %
        $this->overtime('2026-10-12', 300);
        $this->overtime('2026-10-14', 240);
        // Non validées : ignorées
        $this->overtime('2026-10-20', 600, 'en_attente');

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare')->assertOk();
        $items = $this->items();

        $this->assertSame(10.0, (float) $items['HS12']['quantity']); // 2 + 8
        $this->assertSame(3.0, (float) $items['HS35']['quantity']);  // 2 + 1
    }

    public function test_prepare_collects_recurring_leaves_and_absences_and_keeps_manual_items(): void
    {
        RecurringPayItem::create(['employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['IND_TRANSPORT']->id, 'amount' => 43.2, 'start_month' => '2026-01']);
        RecurringPayItem::create(['employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['IND_TRANSPORT']->id, 'amount' => 99, 'start_month' => '2026-01', 'end_month' => '2026-09']);

        $cp = LeaveType::create(['name' => 'Congés payés', 'code' => 'CP']);
        // Du 28/10 au 04/11 : seuls les jours d'octobre comptent (28, 29, 30 → 3 jours ; le 30 au matin seulement)
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $cp->id, 'start_date' => '2026-10-28', 'end_date' => '2026-11-04', 'days' => 5, 'status' => 'approuve']);
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $cp->id, 'start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'end_half_day' => true, 'days' => 0.5, 'status' => 'approuve']);

        // Pointages : présent le 01/10, absent le 02/10 (non justifié), présent ensuite
        foreach (['2026-10-01', '2026-10-05', '2026-10-06'] as $d) {
            Attendance::create(['employee_id' => $this->employee->id, 'date' => $d, 'clock_in' => "{$d} 09:00", 'clock_out' => "{$d} 17:00"]);
        }

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/items', [
            'employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['PRIME_EXC']->id, 'amount' => 500, 'comment' => 'Projet X',
        ])->assertCreated();

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare')->assertOk();
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare')->assertOk(); // recalcul : pas de doublon

        $items = $this->items();
        $this->assertSame(43.2, (float) $items['IND_TRANSPORT']['amount']);
        $this->assertSame(3.5, (float) $items['CP']['quantity']);
        $this->assertSame('conge', $items['CP']['source']);
        $this->assertSame(500.0, (float) $items['PRIME_EXC']['amount']);
        $this->assertSame('manuel', $items['PRIME_EXC']['source']);
        $this->assertGreaterThan(0, $items['ABS_NJ']['quantity']);
        $this->assertSame(4, PayrollItem::count());

        $this->actingAs($this->rh)->getJson('/api/v1/payroll/periods/2026-10')->assertJsonPath('employees.0.monthly_salary', 3000);
    }

    public function test_item_validation_by_unit(): void
    {
        $url = '/api/v1/payroll/periods/2026-10/items';
        $this->actingAs($this->rh)->postJson($url, ['employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['ACOMPTE']->id])->assertJsonValidationErrors('amount');
        $this->actingAs($this->rh)->postJson($url, ['employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['HS12']->id, 'amount' => 10])->assertJsonValidationErrors('quantity');
    }

    public function test_editing_auto_item_makes_it_manual(): void
    {
        $this->overtime('2026-10-12', 120);
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare');
        $id = PayrollItem::first()->id;

        $this->actingAs($this->rh)->putJson("/api/v1/payroll/items/{$id}", ['payroll_rubric_id' => $this->rubrics['HS12']->id, 'quantity' => 3, 'comment' => 'Ajusté'])
            ->assertJsonPath('source', 'manuel');
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare');

        // L'ajustement manuel remplace le calcul : pas de double comptage
        $this->assertSame(1, PayrollItem::count());
        $this->assertSame(3.0, PayrollItem::first()->quantity);
    }

    public function test_close_locks_period_and_reopen_is_traced(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/close')->assertJsonValidationErrors('period'); // pas préparée
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare');
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/close')->assertOk()->assertJsonPath('status', 'cloturee');

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare')->assertJsonValidationErrors('period');
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/items', [
            'employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['PRIME_EXC']->id, 'amount' => 1,
        ])->assertJsonValidationErrors('period');

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/reopen')->assertJsonValidationErrors('reason');
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/reopen', ['reason' => 'Prime oubliée'])->assertJsonPath('status', 'ouverte');

        $this->assertDatabaseHas('activity_log', ['description' => 'Réouverture de la paie']);
    }

    public function test_exports(): void
    {
        $this->overtime('2026-10-12', 90);
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/items', [
            'employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['PRIME_EXC']->id, 'amount' => 150.5,
        ]);
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/prepare');

        $csv = file_get_contents($this->actingAs($this->rh)->get('/api/v1/payroll/periods/2026-10/export?format=csv&layout=lignes')
            ->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Matricule;Nom;Prénom;Période;"Code rubrique"', $csv);
        $this->assertStringContainsString('EMP00042', $csv);
        $this->assertStringContainsString(';P100;', $csv);
        $this->assertStringContainsString('150,5', $csv);    // virgule décimale
        $this->assertStringContainsString(';H112;', $csv);

        $table = file_get_contents($this->actingAs($this->rh)->get('/api/v1/payroll/periods/2026-10/export?format=csv&layout=tableau')
            ->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('HS12 (heures)', $table);
        $this->assertStringContainsString('1,5', $table);

        $this->actingAs($this->rh)->get('/api/v1/payroll/periods/2026-10/export?format=xlsx&layout=lignes')->assertOk();
    }

    public function test_payslips_are_distributed_by_matricule(): void
    {
        Storage::fake('local');
        Notification::fake();

        $result = $this->actingAs($this->rh)->post('/api/v1/payroll/periods/2026-10/payslips', [
            'files' => [
                UploadedFile::fake()->create('bulletin_EMP00042_2026-10.pdf', 80, 'application/pdf'),
                UploadedFile::fake()->create('bulletin_EMP99999_2026-10.pdf', 80, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertCount(1, $result['matched']);
        $this->assertSame(['bulletin_EMP99999_2026-10.pdf'], $result['unmatched']);

        $document = EmployeeDocument::first();
        $this->assertSame('Bulletin de paie — Octobre 2026', $document->title);
        $this->assertSame('PAIE', $document->category->code);
        Notification::assertSentTo($this->employee->user, SimpleNotification::class, fn ($n) => str_contains($n->titleText, 'bulletin de paie'));

        // L'employé voit son bulletin dans son coffre-fort
        $this->actingAs($this->employee->user)->getJson("/api/v1/employees/{$this->employee->id}/documents")->assertJsonCount(1);
    }

    public function test_employees_included_by_entry_and_exit_dates(): void
    {
        Employee::factory()->create(['hire_date' => '2026-11-02']);                                                // entré après
        Employee::factory()->create(['hire_date' => '2020-01-01', 'status' => 'sorti', 'exit_date' => '2026-09-30', 'exit_reason' => 'x']); // sorti avant
        $leaver = Employee::factory()->create(['hire_date' => '2020-01-01', 'status' => 'sorti', 'exit_date' => '2026-10-15', 'exit_reason' => 'x']);

        $employees = collect($this->actingAs($this->rh)->getJson('/api/v1/payroll/periods/2026-10')->json('employees'));
        $this->assertCount(2, $employees);
        $this->assertSame('2026-10-15', $employees->firstWhere('id', $leaver->id)['exit']);
    }

    public function test_rubrics_and_recurring_management(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/rubrics', ['code' => 'prime x', 'label' => 'X', 'kind' => 'gain', 'unit' => 'montant'])->assertJsonValidationErrors('code');
        $this->actingAs($this->rh)->postJson('/api/v1/payroll/rubrics', ['code' => 'PRIME_X', 'label' => 'Prime X', 'kind' => 'gain', 'unit' => 'montant', 'export_code' => 'P999'])->assertCreated();

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/recurring', [
            'employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['HS12']->id, 'amount' => 10, 'start_month' => '2026-10',
        ])->assertJsonValidationErrors('payroll_rubric_id'); // rubrique en heures : pas de récurrence en montant

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/recurring', [
            'employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['IND_TRANSPORT']->id, 'amount' => 43.2, 'start_month' => '2026-10', 'end_month' => '2026-09',
        ])->assertJsonValidationErrors('end_month');

        $this->actingAs($this->rh)->postJson('/api/v1/payroll/periods/2026-10/items', [
            'employee_id' => $this->employee->id, 'payroll_rubric_id' => $this->rubrics['ACOMPTE']->id, 'amount' => 200,
        ]);
        $this->actingAs($this->rh)->deleteJson("/api/v1/payroll/rubrics/{$this->rubrics['ACOMPTE']->id}")->assertStatus(422);
    }
}
