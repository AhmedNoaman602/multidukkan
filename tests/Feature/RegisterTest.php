<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multidukkan.invite_code' => 'MULTI2026']);

        $this->payload = [
            'business_name'         => 'Test Shop',
            'name'                  => 'Ahmed',
            'email'                 => 'new@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'invite_code'           => 'MULTI2026',
        ];
    }

    public function test_registers_with_correct_invite_code_on_max_plan(): void
    {
        $this->postJson('/api/register', $this->payload)
            ->assertStatus(201)
            ->assertJsonPath('user.plan', Tenant::PLAN_MAX)
            ->assertJsonPath('user.early_access', true);

        $this->assertDatabaseHas('tenants', ['name' => 'Test Shop', 'plan' => Tenant::PLAN_MAX]);
        $this->assertDatabaseHas('users', ['email' => 'new@test.com']);
    }

    public function test_wrong_invite_code_is_rejected_and_creates_nothing(): void
    {
        $this->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/register', ['invite_code' => 'WRONG'] + $this->payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code' => 'Invalid invitation code']);

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_invite_code_is_rejected_and_creates_nothing(): void
    {
        unset($this->payload['invite_code']);

        $this->postJson('/api/register', $this->payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('invite_code');

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_closed_when_no_invite_code_is_configured(): void
    {
        config(['multidukkan.invite_code' => null]);

        $this->postJson('/api/register', $this->payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('invite_code');

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invite_code_ignores_case_and_surrounding_spaces(): void
    {
        $this->postJson('/api/register', ['invite_code' => '  multi2026 '] + $this->payload)
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'new@test.com']);
    }
}
