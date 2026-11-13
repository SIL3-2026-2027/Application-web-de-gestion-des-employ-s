<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Simule une requête provenant de la SPA (domaine déclaré dans SANCTUM_STATEFUL_DOMAINS)
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_user_can_login_and_fetch_profile(): void
    {
        $user = User::factory()->create(['email' => 'a@test.fr']);

        $this->postJson('/api/v1/login', ['email' => 'a@test.fr', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'a@test.fr');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'a@test.fr']);

        $this->postJson('/api/v1/login', ['email' => 'a@test.fr', 'password' => 'mauvais'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['email' => 'a@test.fr', 'is_active' => false]);

        $this->postJson('/api/v1/login', ['email' => 'a@test.fr', 'password' => 'password'])
            ->assertUnprocessable();
    }

    public function test_login_is_blocked_after_five_failures(): void
    {
        User::factory()->create(['email' => 'a@test.fr']);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/login', ['email' => 'a@test.fr', 'password' => 'mauvais']);
        }

        $this->postJson('/api/v1/login', ['email' => 'a@test.fr', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonFragment(['email' => ['Trop de tentatives. Réessayez dans 15 minute(s).']]);
    }

    public function test_guest_cannot_access_api(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    }
}
