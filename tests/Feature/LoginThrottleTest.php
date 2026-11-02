<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Everyone signs in daily now (12-hour logins), often several people behind one internet address,
// so only wrong passwords count toward the limit, and per email + address.
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
            'email'     => $email,
            'password'  => bcrypt('password123'),
        ]);
    }

    private function login(string $email, string $password = 'password123')
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_six_people_on_one_internet_address_can_all_sign_in(): void
    {
        foreach (range(1, 6) as $n) {
            $this->user("staff{$n}@test.com");
            $this->login("staff{$n}@test.com")->assertOk();
        }

        $this->assertDatabaseCount('personal_access_tokens', 6);
    }

    public function test_one_person_can_sign_in_six_times_in_a_minute(): void
    {
        $this->user('owner@test.com');

        foreach (range(1, 6) as $n) {
            $this->login('owner@test.com')->assertOk();
        }
    }

    public function test_the_sixth_wrong_password_in_a_minute_is_refused(): void
    {
        $this->user('owner@test.com');

        foreach (range(1, 5) as $n) {
            $this->login('owner@test.com', 'wrong-password')->assertUnauthorized();
        }

        $this->login('owner@test.com')->assertTooManyRequests();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_changing_the_letter_case_of_the_email_does_not_reset_the_limit(): void
    {
        $this->user('owner@test.com');

        foreach (['owner@test.com', 'Owner@test.com', 'OWNER@TEST.COM', 'owner@Test.com', 'oWner@test.com'] as $email) {
            $this->login($email, 'wrong-password')->assertUnauthorized();
        }

        $this->login('owner@test.com', 'wrong-password')->assertTooManyRequests();
    }

    public function test_wrong_passwords_for_one_account_do_not_block_another(): void
    {
        $this->user('owner@test.com');
        $this->user('staff@test.com');

        foreach (range(1, 5) as $n) {
            $this->login('owner@test.com', 'wrong-password')->assertUnauthorized();
        }

        $this->login('staff@test.com')->assertOk();
    }

    public function test_the_limit_resets_after_a_minute(): void
    {
        $this->user('owner@test.com');

        foreach (range(1, 5) as $n) {
            $this->login('owner@test.com', 'wrong-password')->assertUnauthorized();
        }

        $this->travel(61)->seconds();

        $this->login('owner@test.com')->assertOk();
    }
}
