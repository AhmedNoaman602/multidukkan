<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterDefaultUnitsTest extends TestCase
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

    private function unitsAfterRegisteringIn(string $locale): array
    {
        $this->withHeader('X-Locale', $locale)
            ->postJson('/api/register', $this->payload)
            ->assertStatus(201);

        $tenantId = User::where('email', 'new@test.com')->value('tenant_id');

        return Unit::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->pluck('name')->all();
    }

    public function test_registering_in_english_creates_english_units(): void
    {
        $this->assertEquals(['Piece', 'Meter', 'Coil', 'Roll'], $this->unitsAfterRegisteringIn('en'));
    }

    public function test_registering_in_arabic_creates_arabic_units(): void
    {
        $this->assertEquals(['حتة', 'متر', 'لفة', 'رول'], $this->unitsAfterRegisteringIn('ar'));
    }
}
