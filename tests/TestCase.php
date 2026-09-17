<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite runs migrate:fresh against whatever connection it is handed, so a
     * misconfigured DB_DATABASE would drop every table in the development schema.
     * Refusing anything not named *_test makes that unreachable, and this runs
     * before RefreshDatabase gets the chance.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run the test suite against database '{$database}' on connection "
                ."'{$connection}'. The test database name must end in '_test'."
            );
        }

        return $app;
    }
}
