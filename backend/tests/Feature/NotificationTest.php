<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\ContractDeadline;
use App\Notifications\LeaveRequestDecided;
use App\Notifications\LeaveRequestToApprove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationTest extends TestCase
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
        $this->employee = $this->userWithRole(User::ROLE_EMPLOYE, ['manager_id' => $this->manager->employee->id]);
    }

    private function userWithRole(string $role, array $employee = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id, 'hire_date' => '2020-01-01', ...$employee]);

        return $user->fresh();
    }

    private function submit(User $as, array $data = []): int
    {
        return $this->actingAs($as)->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->cp->id,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-16',
            ...$data,
        ])->assertCreated()->json('data.id');
    }

    public function test_manager_is_notified_of_new_request(): void
    {
        $this->submit($this->employee);

        $this->assertCount(1, $this->manager->notifications);
        $this->assertCount(0, $this->rh->notifications);
        $this->assertCount(0, $this->employee->notifications);

        $data = $this->manager->notifications->first()->data;
        $this->assertSame('leave_to_approve', $data['category']);
        $this->assertStringContainsString('5 jours de Congés payés du 12/10/2026 au 16/10/2026', $data['message']);
        $this->assertSame('/conges/validations', $data['url']);
    }

    public function test_hr_is_notified_when_employee_has_no_manager(): void
    {
        $alone = $this->userWithRole(User::ROLE_EMPLOYE);
        $this->submit($alone);

        $this->assertCount(1, $this->rh->notifications);
        $this->assertCount(0, $this->manager->notifications);
    }

    public function test_two_step_approval_notifies_employee_then_hr(): void
    {
        $css = LeaveType::create(['name' => 'Sans solde', 'code' => 'CSS', 'is_deductible' => false, 'requires_hr_approval' => true]);
        $id = $this->submit($this->employee, ['leave_type_id' => $css->id]);

        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();

        $this->assertStringContainsString('validée par votre manager', $this->employee->notifications->first()->data['title']);
        $this->assertSame('leave_to_approve', $this->rh->notifications->first()->data['category']);

        $this->travel(1)->minutes();
        $this->actingAs($this->rh)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();
        $this->assertStringContainsString('approuvée', $this->employee->fresh()->notifications->first()->data['title']);
    }

    public function test_refusal_reason_is_sent_to_employee(): void
    {
        $id = $this->submit($this->employee);
        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/reject", ['reason' => 'Clôture comptable'])->assertOk();

        $data = $this->employee->notifications->first()->data;
        $this->assertStringContainsString('refusée', $data['title']);
        $this->assertStringContainsString('Clôture comptable', $data['message']);
    }

    public function test_absence_declared_by_hr_notifies_employee_only(): void
    {
        $this->submit($this->rh, ['employee_id' => $this->employee->employee->id]);

        $this->assertStringContainsString('enregistré par les RH', $this->employee->notifications->first()->data['title']);
        $this->assertCount(0, $this->manager->notifications);
    }

    public function test_employee_cancelling_own_request_notifies_nobody(): void
    {
        $id = $this->submit($this->employee);
        $this->actingAs($this->employee)->postJson("/api/v1/leave-requests/{$id}/cancel")->assertOk();

        $this->assertCount(0, $this->employee->notifications);
    }

    public function test_email_follows_user_preferences(): void
    {
        Notification::fake();

        $this->submit($this->employee);
        Notification::assertSentTo($this->manager, LeaveRequestToApprove::class, fn ($n, array $channels) => $channels === ['database', 'mail']);

        $this->manager->update(['notification_preferences' => ['leave_to_approve' => false]]);
        $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-03']);
        Notification::assertSentTo($this->manager, LeaveRequestToApprove::class, fn ($n, array $channels) => $channels === ['database']);
    }

    public function test_email_content(): void
    {
        $id = $this->submit($this->employee);
        $this->actingAs($this->manager)->postJson("/api/v1/leave-requests/{$id}/approve");

        $mail = (new LeaveRequestDecided(\App\Models\LeaveRequest::find($id)))->toMail($this->employee);
        $this->assertSame('Votre demande de Congés payés est approuvée', $mail->subject);
        $this->assertSame('http://localhost:5173/conges', $mail->actionUrl);
    }

    public function test_bell_api_lists_and_marks_as_read(): void
    {
        $this->submit($this->employee);
        $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-03']);

        $response = $this->actingAs($this->manager)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.read', false);

        $this->actingAs($this->manager)->postJson('/api/v1/notifications/'.$response->json('data.0.id').'/read')
            ->assertJsonPath('unread_count', 1);

        // Impossible de lire la notification de quelqu'un d'autre
        $this->actingAs($this->employee)->postJson('/api/v1/notifications/'.$response->json('data.1.id').'/read')->assertNotFound();

        $this->actingAs($this->manager)->postJson('/api/v1/notifications/read-all')->assertJsonPath('unread_count', 0);
        $this->actingAs($this->manager)->getJson('/api/v1/notifications?unread=1')->assertJsonCount(0, 'data');
    }

    public function test_preferences_api(): void
    {
        $this->actingAs($this->employee)->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->assertJsonFragment(['key' => 'leave_decision', 'email' => true]);

        $this->actingAs($this->employee)->putJson('/api/v1/notification-preferences', ['email' => ['leave_decision' => false, 'inconnu' => true]])
            ->assertOk()
            ->assertJsonFragment(['key' => 'leave_decision', 'email' => false]);

        $this->assertSame(['leave_decision' => false], $this->employee->fresh()->notification_preferences);
    }

    public function test_contract_deadlines_are_notified_at_j30_and_j7(): void
    {
        Notification::fake();
        $employee = $this->employee->employee;
        Contract::create(['employee_id' => $employee->id, 'type' => 'CDD', 'start_date' => '2026-04-01', 'end_date' => '2026-10-08', 'weekly_hours' => 35, 'base_salary' => 30000]);
        $other = Employee::factory()->create();
        Contract::create(['employee_id' => $other->id, 'type' => 'CDI', 'start_date' => '2026-09-01', 'trial_end_date' => '2026-10-09', 'weekly_hours' => 35, 'base_salary' => 30000]);

        $this->artisan('notifications:contract-deadlines')->expectsOutputToContain('1 échéance(s)');

        Notification::assertSentTo([$this->rh, $this->manager], ContractDeadline::class, fn (ContractDeadline $n) => $n->daysLeft === 7 && $n->kind === 'fin_contrat');
        Notification::assertNotSentTo($this->employee, ContractDeadline::class);
    }
}
