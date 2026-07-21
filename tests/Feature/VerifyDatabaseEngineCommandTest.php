<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifyDatabaseEngineCommandTest extends TestCase
{
    public function test_it_skips_the_check_on_non_mysql_connections(): void
    {
        $this->artisan('db:verify-engine')
            ->expectsOutputToContain("Skipping engine check — connection driver is 'sqlite', not MySQL/MariaDB.")
            ->assertExitCode(0);
    }

    public function test_it_passes_when_every_mysql_table_is_innodb(): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')->once()->andReturn([
            (object) ['table_name' => 'orders', 'engine' => 'InnoDB'],
            (object) ['table_name' => 'ledger_entries', 'engine' => 'InnoDB'],
        ]);

        $this->artisan('db:verify-engine')
            ->expectsOutputToContain('All 2 tables use InnoDB.')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_a_mysql_table_is_not_innodb(): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');

        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('select')->once()->andReturn([
            (object) ['table_name' => 'orders', 'engine' => 'InnoDB'],
            (object) ['table_name' => 'legacy_search_index', 'engine' => 'MyISAM'],
        ]);

        $this->artisan('db:verify-engine')
            ->expectsOutputToContain('The following tables are not using InnoDB:')
            ->expectsOutputToContain('legacy_search_index: MyISAM')
            ->assertExitCode(1);
    }
}
