<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyDatabaseEngine extends Command
{
    protected $signature = 'db:verify-engine';

    protected $description = 'Verify every table on the current connection uses the InnoDB storage engine';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->info("Skipping engine check — connection driver is '{$driver}', not MySQL/MariaDB.");

            return self::SUCCESS;
        }

        $tables = DB::select(
            "SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        $offending = array_filter($tables, fn ($table) => strtoupper((string) $table->engine) !== 'INNODB');

        if (count($offending) > 0) {
            $this->error('The following tables are not using InnoDB:');

            foreach ($offending as $table) {
                $this->line("  - {$table->table_name}: ".($table->engine ?? 'NULL'));
            }

            return self::FAILURE;
        }

        $this->info('All '.count($tables).' tables use InnoDB.');

        return self::SUCCESS;
    }
}
