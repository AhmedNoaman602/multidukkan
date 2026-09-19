<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifySqlModeCommandTest extends TestCase
{
    private function mockDriver(string $driver): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn($driver);

        DB::shouldReceive('connection')->andReturn($connection);
    }

    public function test_it_skips_the_check_on_non_mysql_connections(): void
    {
        $this->mockDriver('sqlite');

        $this->artisan('db:verify-sql-mode')
            ->expectsOutputToContain("Skipping SQL mode check — connection driver is 'sqlite', not MySQL/MariaDB.")
            ->assertExitCode(0);
    }

    public function test_it_passes_when_the_session_and_server_are_both_strict(): void
    {
        $this->mockDriver('mysql');

        DB::shouldReceive('select')->once()->andReturn([(object) ['mode' => 'STRICT_TRANS_TABLES,NO_ZERO_DATE']]);
        DB::shouldReceive('select')->once()->andReturn([(object) ['mode' => 'STRICT_TRANS_TABLES']]);

        $this->artisan('db:verify-sql-mode')
            ->expectsOutputToContain('STRICT_TRANS_TABLES is active on this connection.')
            ->assertExitCode(0);
    }

    public function test_it_warns_but_passes_when_only_the_server_default_is_missing_strict(): void
    {
        $this->mockDriver('mysql');

        DB::shouldReceive('select')->once()->andReturn([(object) ['mode' => 'STRICT_TRANS_TABLES']]);
        DB::shouldReceive('select')->once()->andReturn([(object) ['mode' => '']]);

        $this->artisan('db:verify-sql-mode')
            ->expectsOutputToContain('is not in the server default')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_the_session_is_not_strict(): void
    {
        $this->mockDriver('mysql');

        DB::shouldReceive('select')->once()->andReturn([(object) ['mode' => 'NO_ZERO_DATE']]);
        DB::shouldReceive('select')->once()->andReturn([(object) ['mode' => 'NO_ZERO_DATE']]);

        $this->artisan('db:verify-sql-mode')
            ->expectsOutputToContain('is missing from the session')
            ->assertExitCode(1);
    }
}
