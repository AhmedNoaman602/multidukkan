<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifySqlMode extends Command
{
    protected $signature = 'db:verify-sql-mode';

    protected $description = 'Report whether STRICT_TRANS_TABLES is active on this connection and on the server default';

    private const REQUIRED = 'STRICT_TRANS_TABLES';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->info("Skipping SQL mode check — connection driver is '{$driver}', not MySQL/MariaDB.");

            return self::SUCCESS;
        }

        $session = (string) DB::select('SELECT @@SESSION.sql_mode AS mode')[0]->mode;
        $global  = (string) DB::select('SELECT @@GLOBAL.sql_mode AS mode')[0]->mode;

        $sessionStrict = str_contains($session, self::REQUIRED);
        $globalStrict  = str_contains($global, self::REQUIRED);

        $this->line('session sql_mode: '.($session !== '' ? $session : '(empty)'));
        $this->line('global  sql_mode: '.($global !== '' ? $global : '(empty)'));

        if (! $sessionStrict) {
            $this->error(self::REQUIRED.' is missing from the session. Money and stock writes can truncate silently — check \'strict\' in config/database.php.');

            return self::FAILURE;
        }

        $this->info(self::REQUIRED.' is active on this connection.');

        if (! $globalStrict) {
            $this->warn(self::REQUIRED.' is not in the server default. Laravel sets it per session, so the application is safe, but CLI imports, phpMyAdmin and backup restores are not. See docs/DATABASE_GUIDE.md.');
        }

        return self::SUCCESS;
    }
}
