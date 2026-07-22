<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Named-limiter keys are md5(limiterName.userId) (see ThrottleRequests), and the array
        // cache store persists for the process lifetime while RefreshDatabase's per-test
        // transaction rollback resets auto-increment — so the same user id (and thus the same
        // rate-limit key) can recur across tests. Flush the whole store so counts never leak in.
        Cache::flush();

        // AIService's constructor references Prism\Prism\Enums\Provider, a package that isn't
        // installed in this environment (pre-existing gap, unrelated to rate limiting) — bind a
        // stand-in instance so AIController can be resolved without invoking the real constructor.
        $this->app->instance(AIService::class, \Mockery::mock(AIService::class));
    }

    private function actingAsUser(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);
    }

    public function test_general_authenticated_endpoint_is_rate_limited(): void
    {
        $user = $this->actingAsUser();

        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($user)->getJson('/api/me')->assertStatus(200);
        }

        $this->actingAs($user)->getJson('/api/me')->assertStatus(429);
    }

    public function test_ai_endpoint_is_rate_limited_before_the_general_limit(): void
    {
        $user = $this->actingAsUser();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->getJson('/api/ai/insights')->assertStatus(200);
        }

        // The 11th AI call trips the tighter ai limiter well before the general 120/min cap.
        $this->actingAs($user)->getJson('/api/ai/insights')->assertStatus(429);
    }

    public function test_ai_and_general_limiters_are_tracked_independently(): void
    {
        $user = $this->actingAsUser();

        // Exhaust the AI limiter only.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->getJson('/api/ai/insights')->assertStatus(200);
        }
        $this->actingAs($user)->getJson('/api/ai/insights')->assertStatus(429);

        // A non-AI endpoint for the same user is unaffected.
        $this->actingAs($user)->getJson('/api/me')->assertStatus(200);
    }
}
