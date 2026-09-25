<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

final class TestDatabaseSafetyTest extends TestCase
{
    #[DataProvider('unsafeConfigurations')]
    public function test_rejects_unsafe_targets_without_connecting(array $config, string $environment = 'testing'): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseSafety::assertSafe($config, $environment);
    }

    public static function unsafeConfigurations(): array
    {
        $safe = ['driver' => 'pgsql', 'database' => 'systek_test'];

        return [
            'development database' => [['driver' => 'pgsql', 'database' => 'systek']],
            'persistent sqlite' => [['driver' => 'sqlite', 'database' => '/tmp/dev.sqlite']],
            'wrong environment' => [$safe, 'local'],
            'url overrides database' => [$safe + ['url' => 'postgresql://localhost/systek']],
            'url query overrides database' => [$safe + ['url' => 'postgresql://localhost/systek_test?database=systek']],
            'alternate postgres database' => [$safe + ['connect_via_database' => 'systek']],
            'alternate database in url' => [$safe + ['url' => 'postgresql://localhost/systek_test?connect_via_database=systek']],
            'direct migration target' => [$safe + ['direct' => ['database' => 'systek']]],
            'read target' => [$safe + ['read' => ['database' => 'systek']]],
            'write target' => [$safe + ['write' => ['database' => 'systek']]],
            'unsupported driver' => [['driver' => 'mysql', 'database' => 'systek_test']],
            'missing configuration' => [[]],
        ];
    }

    public function test_accepts_only_explicit_memory_or_dedicated_postgres_targets(): void
    {
        foreach ([['driver' => 'sqlite', 'database' => ':memory:'], ['driver' => 'pgsql', 'database' => 'systek_test']] as $config) {
            TestDatabaseSafety::assertSafe($config, 'testing');
        }
        $this->addToAssertionCount(2);
    }
}
