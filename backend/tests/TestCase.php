<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Docker exports these into both environment arrays; PHPUnit env alone cannot override them.
        foreach (['APP_ENV' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        $app = parent::createApplication();
        $app->make('config')->set(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array']);
        // Validate configuration without opening a PDO, before RefreshDatabase can migrate.
        $default = $app->make('config')->get('database.default');
        $connections = method_exists($this, 'connectionsToTransact') ? $this->connectionsToTransact() : [];
        foreach (array_unique([$default, ...$connections]) as $name) {
            $config = $app->make('config')->get('database.connections.'.($name ?? $default));
            if (! is_array($config)) {
                throw new \RuntimeException('Pruebas bloqueadas: conexión de prueba no configurada.');
            }
            TestDatabaseSafety::assertSafe($config, $app->environment());
        }

        return $app;
    }
}
