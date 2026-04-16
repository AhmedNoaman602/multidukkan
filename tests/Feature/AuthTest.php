<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Test Tenant']);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Ahmed',
            'email'     => 'ahmed@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'tenant_admin',
        ]);
    }

    // ─────────────────────────────────────────
    // LOGIN
    // ─────────────────────────────────────────

    public function test_user_can_login_with_valid_credentials(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'ahmed@test.com',
            'password' => 'password123',
        ])->assertStatus(200)
          ->assertJsonStructure([
              'token',
              'user' => ['id', 'name', 'email', 'role', 'tenant_id'],
          ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'ahmed@test.com',
            'password' => 'wrongpassword',
        ])->assertStatus(401)
          ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $this->postJson('/api/login', [
            'email'    => 'nobody@test.com',
            'password' => 'password123',
        ])->assertStatus(401)
          ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_requires_email(): void
    {
        $this->postJson('/api/login', [
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_login_requires_password(): void
    {
        $this->postJson('/api/login', [
            'email' => 'ahmed@test.com',
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // LOGOUT
    // ─────────────────────────────────────────

    public function test_user_can_logout(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/logout')
            ->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully.']);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')
            ->assertStatus(401);
    }

    public function test_token_is_deleted_after_logout(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertStatus(200);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ─────────────────────────────────────────
    // ME
    // ─────────────────────────────────────────

    public function test_me_returns_authenticated_user(): void
    {
       $this->actingAs($this->user)
    ->getJson('/api/me')
    ->assertStatus(200)
    ->assertJson([
        'id'            => $this->user->id,
        'email'         => $this->user->email,
        'role'          => 'tenant_admin',
        'tenant_id'     => $this->user->tenant_id,
        'business_name' => 'Test Tenant',
    ]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_me_returns_has_store_false_for_new_tenant()
{
    $user = User::factory()->create(['role' => 'tenant_admin']);

    $response = $this->actingAs($user)->getJson('/api/me');

    $response->assertOk()->assertJsonFragment(['has_store' => false]);
}

public function test_me_returns_has_store_true_when_store_exists()
{
    $user = User::factory()->create(['role' => 'tenant_admin']);
    \App\Models\Store::factory()->create(['tenant_id' => $user->tenant_id]);

    $response = $this->actingAs($user)->getJson('/api/me');

    $response->assertOk()->assertJsonFragment(['has_store' => true]);
}
}