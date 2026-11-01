<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// A login lasts 12 hours (SANCTUM_EXPIRATION, in minutes); after that the user must log in again.
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
            'email'     => 'owner@test.com',
            'password'  => bcrypt('password123'),
        ]);
    }

    private function login(): string
    {
        return $this->postJson('/api/login', ['email' => 'owner@test.com', 'password' => 'password123'])
            ->assertOk()
            ->json('token');
    }

    public function test_a_login_lasts_twelve_hours_by_default(): void
    {
        $this->assertSame(720, config('sanctum.expiration'));
    }

    public function test_a_fresh_login_works(): void
    {
        $token = $this->login();

        $this->withToken($token)->getJson('/api/me')->assertOk();
    }

    public function test_a_login_still_works_just_before_twelve_hours(): void
    {
        $token = $this->login();

        $this->travel(11 * 60 + 59)->minutes();

        $this->withToken($token)->getJson('/api/me')->assertOk();
    }

    public function test_a_login_older_than_twelve_hours_is_refused(): void
    {
        $token = $this->login();

        $this->travel(12 * 60 + 1)->minutes();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_logging_in_again_after_expiry_works(): void
    {
        $this->login();

        $this->travel(12 * 60 + 1)->minutes();

        $this->withToken($this->login())->getJson('/api/me')->assertOk();
    }
}
