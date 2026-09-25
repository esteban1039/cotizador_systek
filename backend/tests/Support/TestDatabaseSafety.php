<?php

namespace Tests\Support;

use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;

final class TestDatabaseSafety
{
    public static function assertSafe(array $config, string $environment): void
    {
        $resolved = (new ConfigurationUrlParser)->parseConfiguration($config);
        // Alternate PDO targets can differ from Connection::getDatabaseName().
        foreach (['read', 'write', 'direct', 'connect_via_database', 'connect_via_port'] as $key) {
            if (array_key_exists($key, $config) || array_key_exists($key, $resolved)) {
                throw new RuntimeException('Pruebas bloqueadas: no se permiten conexiones alternativas.');
            }
        }
        $safe = (($resolved['driver'] ?? null) === 'sqlite' && ($resolved['database'] ?? null) === ':memory:')
            || (($resolved['driver'] ?? null) === 'pgsql' && ($resolved['database'] ?? null) === 'systek_test');
        if ($environment !== 'testing' || ! $safe) {
            throw new RuntimeException('Pruebas bloqueadas: usa SQLite :memory: o PostgreSQL systek_test con APP_ENV=testing.');
        }
    }
}
