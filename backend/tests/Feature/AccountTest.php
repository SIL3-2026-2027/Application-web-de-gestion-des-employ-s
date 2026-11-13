<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Bonjour2026!';

    private User $admin;
    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:5173'); // requêtes de la SPA (session)

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);
        $this->rh = User::factory()->create();
        $this->rh->assignRole(User::ROLE_RH);
    }

    /** Simule une autre personne dans un autre navigateur : nouvelle session, gardes réinitialisés. */
    private function switchTo(User $user): void
    {
        $this->flushSession();
        auth()->forgetGuards();
        $this->actingAs($user);
    }

    private function account(array $data = []): array
    {
        return [
            'email' => 'nouveau@entreprise.test',
            'name' => 'Nouvel Utilisateur',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'roles' => ['employe'],
            ...$data,
        ];
    }

    // ---------- Inscription par l'administration ----------

    public function test_nobody_can_self_register(): void
    {
        $this->postJson('/api/v1/users', $this->account())->assertUnauthorized();

        $employee = User::factory()->create();
        $employee->assignRole(User::ROLE_EMPLOYE);
        $this->actingAs($employee)->postJson('/api/v1/users', $this->account())->assertForbidden();
    }

    public function test_hr_creates_account_with_password_and_no_email_is_sent(): void
    {
        Notification::fake();
        $employee = Employee::factory()->create(['work_email' => 'lea@entreprise.test']);

        $this->actingAs($this->rh)->postJson('/api/v1/users', $this->account([
            'employee_id' => $employee->id,
            'email' => 'lea@entreprise.test',
            'name' => null,
        ]))->assertCreated()
            ->assertJsonPath('data.name', $employee->full_name)
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.must_change_password', true);

        Notification::assertNothingSent();
        $this->assertNotNull($employee->fresh()->user_id);

        // L'employé se connecte avec le mot de passe saisi par les RH
        auth()->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => 'lea@entreprise.test', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_password_policy(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/users', $this->account(['password' => 'simple', 'password_confirmation' => 'simple']))
            ->assertJsonValidationErrors('password');
        $this->actingAs($this->rh)->postJson('/api/v1/users', $this->account(['password_confirmation' => 'Autre2026!xx']))
            ->assertJsonValidationErrors('password');
    }

    public function test_employee_record_and_account_created_together(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/employees', [
            'last_name' => 'Martin',
            'first_name' => 'Paul',
            'work_email' => 'paul@entreprise.test',
            'hire_date' => '2026-10-01',
            'status' => 'periode_essai',
            'account' => [
                'create' => true,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'roles' => ['employe'],
                'must_change_password' => false,
            ],
        ])->assertCreated()->assertJsonPath('data.has_account', true);

        $user = User::where('email', 'paul@entreprise.test')->first();
        $this->assertTrue($user->hasRole('employe'));
        $this->assertFalse($user->must_change_password);
    }

    public function test_employee_creation_with_account_requires_work_email_and_rolls_back(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/employees', [
            'last_name' => 'Martin', 'first_name' => 'Paul', 'hire_date' => '2026-10-01', 'status' => 'actif',
            'account' => ['create' => true, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'roles' => ['employe']],
        ])->assertJsonValidationErrors('work_email');

        // Rôle interdit aux RH : ni dossier ni compte ne sont créés
        $this->actingAs($this->rh)->postJson('/api/v1/employees', [
            'last_name' => 'Martin', 'first_name' => 'Paul', 'work_email' => 'paul@entreprise.test', 'hire_date' => '2026-10-01', 'status' => 'actif',
            'account' => ['create' => true, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'roles' => ['super-admin']],
        ])->assertJsonValidationErrors('roles');

        $this->assertDatabaseMissing('employees', ['work_email' => 'paul@entreprise.test']);
    }

    // ---------- Rôles et droits ----------

    public function test_hr_cannot_grant_admin_roles_or_manage_hr_accounts(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/users', $this->account(['roles' => ['rh']]))->assertJsonValidationErrors('roles');
        $this->actingAs($this->admin)->postJson('/api/v1/users', $this->account(['roles' => ['rh']]))->assertCreated();

        $otherHr = User::where('email', 'nouveau@entreprise.test')->first();
        $this->actingAs($this->rh)->postJson("/api/v1/users/{$otherHr->id}/deactivate")->assertForbidden();
        $this->actingAs($this->rh)->getJson('/api/v1/users')->assertJsonPath('assignable_roles', ['manager', 'employe', 'direction']);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/users/{$this->admin->id}/deactivate")->assertJsonValidationErrors('user');
        $this->actingAs($this->admin)->putJson("/api/v1/users/{$this->admin->id}", ['email' => $this->admin->email, 'roles' => ['rh']])
            ->assertJsonValidationErrors('roles');
    }

    public function test_at_least_one_active_admin_remains(): void
    {
        $other = User::factory()->create();
        $other->assignRole(User::ROLE_ADMIN);

        $this->actingAs($other)->postJson("/api/v1/users/{$this->admin->id}/deactivate")->assertOk();

        // $other est maintenant le seul administrateur actif : il ne peut ni perdre son rôle ni être désactivé
        $this->actingAs($other)->putJson("/api/v1/users/{$other->id}", ['email' => $other->email, 'roles' => ['rh']])
            ->assertJsonValidationErrors('roles');
        $this->actingAs($other)->postJson("/api/v1/users/{$this->admin->id}/activate")->assertOk();
        $this->actingAs($other)->putJson("/api/v1/users/{$this->admin->id}", ['email' => $this->admin->email, 'roles' => ['rh']])->assertOk();
        $this->actingAs($this->rh)->postJson("/api/v1/users/{$other->id}/deactivate")->assertForbidden();
    }

    public function test_deactivated_user_is_logged_out_immediately(): void
    {
        $user = User::factory()->create(['email' => 'x@entreprise.test']);
        $user->assignRole(User::ROLE_EMPLOYE);

        $this->actingAs($this->rh)->postJson("/api/v1/users/{$user->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->switchTo($user->fresh());
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Votre compte a été désactivé.');

        auth()->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => 'x@entreprise.test', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_left_employee_account_cannot_be_reactivated(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(User::ROLE_EMPLOYE);
        Employee::factory()->create(['user_id' => $user->id, 'status' => 'sorti', 'exit_date' => '2026-01-01', 'exit_reason' => 'Démission']);

        $this->actingAs($this->rh)->postJson("/api/v1/users/{$user->id}/activate")->assertJsonValidationErrors('user');
    }

    // ---------- Changement de mot de passe ----------

    public function test_must_change_password_blocks_until_changed(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/users', $this->account())->assertCreated();
        $user = User::where('email', 'nouveau@entreprise.test')->first();

        $this->switchTo($user);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'password_change_required');

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => self::PASSWORD,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertJsonValidationErrors('password'); // identique à l'ancien

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'mauvais',
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Nouveau2026!',
        ])->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => self::PASSWORD,
            'password' => 'Nouveau2026!',
            'password_confirmation' => 'Nouveau2026!',
        ])->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->actingAs($user->fresh())->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_admin_resets_a_password(): void
    {
        $user = User::factory()->create();
        $user->assignRole(User::ROLE_EMPLOYE);

        $this->actingAs($this->rh)->putJson("/api/v1/users/{$user->id}/password", [
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.must_change_password', true);
    }

    // ---------- Mot de passe oublié : seul e-mail envoyé ----------

    public function test_forgot_password_sends_email_and_link_resets_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'oubli@entreprise.test']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'oubli@entreprise.test'])->assertOk();
        $this->postJson('/api/v1/forgot-password', ['email' => 'inconnu@entreprise.test'])->assertOk(); // même réponse

        $token = null;
        Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $n) use (&$token) {
            $token = $n->token;

            return str_contains($n->toMail($n->token ? User::where('email', 'oubli@entreprise.test')->first() : null)->actionUrl, '/definir-mot-de-passe?');
        });
        Notification::assertCount(1);

        $this->postJson('/api/v1/reset-password', [
            'token' => 'faux', 'email' => 'oubli@entreprise.test', 'password' => 'Nouveau2026!', 'password_confirmation' => 'Nouveau2026!',
        ])->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/reset-password', [
            'token' => $token, 'email' => 'oubli@entreprise.test', 'password' => 'Nouveau2026!', 'password_confirmation' => 'Nouveau2026!',
        ])->assertOk();

        $this->postJson('/api/v1/login', ['email' => 'oubli@entreprise.test', 'password' => 'Nouveau2026!'])->assertOk();
    }

    public function test_reset_link_expires_after_30_minutes(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'oubli@entreprise.test']);
        $this->postJson('/api/v1/forgot-password', ['email' => 'oubli@entreprise.test']);

        $token = null;
        Notification::assertSentTo($user, PasswordResetLink::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->travel(31)->minutes();
        $this->postJson('/api/v1/reset-password', [
            'token' => $token, 'email' => 'oubli@entreprise.test', 'password' => 'Nouveau2026!', 'password_confirmation' => 'Nouveau2026!',
        ])->assertJsonValidationErrors('token');
    }
}
