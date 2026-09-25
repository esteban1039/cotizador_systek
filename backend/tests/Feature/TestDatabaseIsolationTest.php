<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TestDatabaseIsolationTest extends TestCase
{
    public function test_suite_uses_only_a_dedicated_test_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $connection = DB::connection();
        $this->assertContains($connection->getDriverName().':'.$connection->getDatabaseName(), ['sqlite::memory:', 'pgsql:systek_test']);
    }
}
