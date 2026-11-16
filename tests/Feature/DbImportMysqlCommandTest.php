<?php

namespace Tests\Feature;

use Tests\TestCase;

class DbImportMysqlCommandTest extends TestCase
{
    public function test_command_refuses_to_run_when_default_connection_is_not_postgresql(): void
    {
        $this->artisan('db:import-mysql')
            ->expectsOutput('This import is allowed only when the default database connection uses PostgreSQL.')
            ->assertExitCode(1);
    }
}
