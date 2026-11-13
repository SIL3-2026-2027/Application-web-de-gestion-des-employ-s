<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\SimpleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $rh;
    private User $manager;
    private User $employee;
    private WorkSchedule $standard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-07 08:55:00'); // mercredi

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->standard = WorkSchedule::create([
            'name' => 'Standard',
            'days' => collect(range(1, 5))->mapWithKeys(fn ($d) => [$d => ['start' => '09:00', 'end' => '17:00', 'break' => 60]])->all(),
            'late_tolerance' => 10,
            'is_default' => true,
        ]);

        $this->rh = $this->userWithRole(User::ROLE_RH);
        $this->manager = $this->userWithRole(User::ROLE_MANAGER);
        $this->employee = $this->userWithRole(User::ROLE_EMPLOYE, ['manager_id' => $this->manager->employee->id]);
    }

    private function userWithRole(string $role, array $employee = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id, 'hire_date' => '2020-01-01', 'status' => 'actif', ...$employee]);

        return $user->fresh();
    }

    private function segment(string $date, string $in, ?string $out): void
    {
        Attendance::create(['employee_id' => $this->employee->employee->id, 'date' => $date, 'clock_in' => "{$date} {$in}", 'clock_out' => $out ? "{$date} {$out}" : null]);
    }

    private function day(array $sheet, string $date): array
    {
        return collect($sheet['days'])->firstWhere('date', $date);
    }

    // ---------- Pointage ----------

    public function test_clock_in_and_out(): void
    {
        $this->actingAs($this->employee)->postJson('/api/v1/attendance/clock-in', ['latitude' => 48.85, 'longitude' => 2.35])
            ->assertOk()->assertJsonPath('status', 'present')->assertJsonPath('segments.0.in', '08:55');
        $this->actingAs($this->employee)->postJson('/api/v1/attendance/clock-in')->assertJsonValidationErrors('clock');

        $this->travelTo('2026-10-07 12:30:00');
        $this->actingAs($this->employee)->postJson('/api/v1/attendance/clock-out')
            ->assertOk()->assertJsonPath('status', 'parti')->assertJsonPath('worked', 215);
        $this->actingAs($this->employee)->postJson('/api/v1/attendance/clock-out')->assertJsonValidationErrors('clock');

        $this->assertSame(48.85, Attendance::first()->latitude);
    }

    public function test_user_without_employee_record_cannot_clock(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->postJson('/api/v1/attendance/clock-in')->assertStatus(422);
    }

    // ---------- Feuille de temps ----------

    public function test_timesheet_computes_late_overtime_absences_holidays_and_leaves(): void
    {
        $this->travelTo('2026-10-31 20:00:00');
        PublicHoliday::create(['date' => '2026-10-12', 'name' => 'Férié test']);
        $cp = LeaveType::create(['name' => 'Congés payés', 'code' => 'CP', 'color' => '#2e75b6']);
        LeaveRequest::create(['employee_id' => $this->employee->employee->id, 'leave_type_id' => $cp->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-16', 'end_half_day' => true, 'days' => 1.5, 'status' => 'approuve']);

        $this->segment('2026-10-05', '09:00', '12:30');
        $this->segment('2026-10-05', '13:30', '17:00'); // 7 h pile
        $this->segment('2026-10-06', '09:25', '18:30'); // retard 25 min, 9 h 05 travaillées sans pause pointée
        // 07/10 : aucun pointage → absence
        $this->segment('2026-10-08', '09:05', null);   // départ non pointé
        $this->segment('2026-10-16', '13:00', '16:30'); // demi-journée de congé le matin… (fin de congé à midi)

        $sheet = $this->actingAs($this->employee)->getJson('/api/v1/attendance/timesheet?month=2026-10')->assertOk()->json();

        $this->assertSame(['worked' => 420, 'late' => 0, 'anomalies' => []], collect($this->day($sheet, '2026-10-05'))->only(['worked', 'late', 'anomalies'])->all());
        $this->assertSame(25, $this->day($sheet, '2026-10-06')['late']);
        $this->assertSame(125, $this->day($sheet, '2026-10-06')['overtime_detected']);
        $this->assertSame(['absence'], $this->day($sheet, '2026-10-07')['anomalies']);
        $this->assertSame(['sortie_manquante'], $this->day($sheet, '2026-10-08')['anomalies']);
        $this->assertSame(0, $this->day($sheet, '2026-10-12')['planned']);   // férié
        $this->assertSame('Férié test', $this->day($sheet, '2026-10-12')['holiday']);
        $this->assertSame(0, $this->day($sheet, '2026-10-15')['planned']);   // congé
        $this->assertSame([], $this->day($sheet, '2026-10-15')['anomalies']);
        $this->assertSame(210, $this->day($sheet, '2026-10-16')['planned']); // demi-journée
        $this->assertSame(0, $this->day($sheet, '2026-10-03')['planned']);   // samedi
        $this->assertSame([], $this->day($sheet, '2026-10-02')['anomalies']); // avant le premier pointage : pas d'absence
    }

    public function test_timesheet_visibility(): void
    {
        $url = "/api/v1/attendance/timesheet?month=2026-10&employee_id={$this->employee->employee->id}";
        $this->actingAs($this->manager)->getJson($url)->assertOk();
        $this->actingAs($this->rh)->getJson($url)->assertOk();
        $this->actingAs($this->userWithRole(User::ROLE_EMPLOYE))->getJson($url)->assertForbidden();
    }

    public function test_team_board(): void
    {
        $this->travelTo('2026-10-07 10:00:00');
        $this->segment('2026-10-07', '09:02', null);
        $other = Employee::factory()->create(['manager_id' => $this->manager->employee->id, 'hire_date' => '2020-01-01', 'status' => 'actif']);

        $board = collect($this->actingAs($this->manager)->getJson('/api/v1/attendance/team')->assertOk()->json());
        $this->assertSame('present', $board->firstWhere('employee.id', $this->employee->employee->id)['status']);
        $this->assertSame('non_arrive', $board->firstWhere('employee.id', $other->id)['status']);
        $this->assertCount(3, $board); // le manager et ses deux équipiers
    }

    // ---------- Heures supplémentaires ----------

    public function test_overtime_request_and_approval(): void
    {
        Notification::fake();

        $this->actingAs($this->employee)->postJson('/api/v1/overtime-requests', ['date' => '2026-10-08', 'minutes' => 60, 'reason' => 'x'])
            ->assertJsonValidationErrors('date'); // pas dans le futur
        $this->actingAs($this->employee)->postJson('/api/v1/overtime-requests', ['date' => '2026-10-06', 'minutes' => 90, 'reason' => 'Livraison client'])->assertCreated();
        Notification::assertSentTo($this->manager, SimpleNotification::class, fn ($n) => str_contains($n->messageText, '1 h 30'));

        $id = OvertimeRequest::first()->id;
        $this->actingAs($this->manager)->getJson('/api/v1/overtime-requests?scope=to_approve')->assertJsonCount(1)->assertJsonPath('0.can_decide', true);
        $this->actingAs($this->employee)->postJson("/api/v1/overtime-requests/{$id}/decide", ['approve' => true])->assertForbidden();
        $this->actingAs($this->manager)->postJson("/api/v1/overtime-requests/{$id}/decide", ['approve' => false])->assertJsonValidationErrors('reason');
        $this->actingAs($this->manager)->postJson("/api/v1/overtime-requests/{$id}/decide", ['approve' => true])->assertOk();
        Notification::assertSentTo($this->employee, SimpleNotification::class, fn ($n) => $n->titleText === 'Heures supplémentaires validées');

        $sheet = $this->actingAs($this->employee)->getJson('/api/v1/attendance/timesheet?month=2026-10')->json();
        $this->assertSame(90, $this->day($sheet, '2026-10-06')['overtime_approved']);
        $this->assertSame(90, $sheet['totals']['overtime_approved']);
    }

    // ---------- Corrections ----------

    public function test_correction_replaces_segments_once_approved(): void
    {
        $this->segment('2026-10-06', '09:00', null);

        $this->actingAs($this->employee)->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-10-06', 'reason' => 'Oubli', 'segments' => [['in' => '13:00', 'out' => '12:00']],
        ])->assertJsonValidationErrors('segments.0.out');

        $this->actingAs($this->employee)->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-10-06', 'reason' => 'Oubli du pointage de départ',
            'segments' => [['in' => '09:00', 'out' => '12:30'], ['in' => '13:30', 'out' => '17:15']],
        ])->assertCreated()->assertJsonPath('status', 'en_attente');

        $this->assertSame(1, Attendance::count()); // rien ne change avant validation

        $id = \App\Models\AttendanceCorrection::first()->id;
        $this->actingAs($this->manager)->postJson("/api/v1/attendance-corrections/{$id}/decide", ['approve' => true])->assertOk();

        $sheet = $this->actingAs($this->employee)->getJson('/api/v1/attendance/timesheet?month=2026-10')->json();
        $day = $this->day($sheet, '2026-10-06');
        $this->assertSame(435, $day['worked']);
        $this->assertSame('correction', $day['segments'][0]['source']);
        $this->assertSame([], $day['anomalies']);
    }

    public function test_hr_entry_is_applied_immediately(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/attendance-corrections', [
            'employee_id' => $this->employee->employee->id, 'date' => '2026-10-06', 'reason' => 'Badge oublié',
            'segments' => [['in' => '09:00', 'out' => '17:00']],
        ])->assertCreated()->assertJsonPath('status', 'approuve');

        $this->assertSame('saisie_rh', Attendance::first()->source);

        // Un employé ne peut pas saisir pour un collègue
        $this->actingAs($this->employee)->postJson('/api/v1/attendance-corrections', [
            'employee_id' => $this->manager->employee->id, 'date' => '2026-10-06', 'reason' => 'x', 'segments' => [['in' => '09:00', 'out' => '17:00']],
        ])->assertForbidden();
    }

    // ---------- Horaires ----------

    public function test_schedules_management(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/work-schedules', [])->assertForbidden();

        $id = $this->actingAs($this->rh)->postJson('/api/v1/work-schedules', [
            'name' => 'Matin', 'late_tolerance' => 5, 'is_default' => true,
            'days' => ['1' => ['start' => '07:00', 'end' => '15:00', 'break' => 30]],
        ])->assertCreated()->json('id');

        $this->assertFalse($this->standard->fresh()->is_default);
        $this->actingAs($this->rh)->getJson('/api/v1/work-schedules')->assertJsonPath('0.weekly_minutes', 450);

        $this->actingAs($this->rh)->putJson("/api/v1/work-schedules/{$id}", [
            'name' => 'Matin', 'late_tolerance' => 5, 'days' => ['1' => ['start' => '15:00', 'end' => '07:00']],
        ])->assertJsonValidationErrors('days.1.end');

        $this->actingAs($this->rh)->deleteJson("/api/v1/work-schedules/{$id}")->assertStatus(422); // horaire par défaut
    }

    // ---------- Anomalies ----------

    public function test_anomalies_command_notifies_manager(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-07 10:30:00');
        $this->segment('2026-10-06', '09:00', '17:00'); // le suivi a commencé

        $this->artisan('attendance:anomalies absences')->assertSuccessful();
        Notification::assertSentTo($this->manager, SimpleNotification::class, fn ($n) => str_contains($n->titleText, 'absence(s)') && str_contains($n->messageText, $this->employee->employee->full_name));

        Notification::fake();
        $this->segment('2026-10-07', '09:00', null);
        $this->travelTo('2026-10-07 21:00:00');
        $this->artisan('attendance:anomalies sorties')->assertSuccessful();
        Notification::assertSentTo($this->employee, SimpleNotification::class, fn ($n) => $n->titleText === 'Départ non pointé');
        Notification::assertSentTo($this->manager, SimpleNotification::class, fn ($n) => str_contains($n->titleText, 'départ(s) non pointé(s)'));
    }
}
