<?php

namespace Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * `PublishClauseVersion` (bloquea `clauses` FOR UPDATE, luego
 * `clause_versions` FOR UPDATE, vía `lockWithCurrentVersion`) y
 * `CreateQuote`/`ClauseSelection` (bloqueo compartido `clauses`, luego
 * `clause_versions`, vía `lockedVersions`) deben bloquear en el mismo orden
 * (cláusula → versión). Con el mismo orden, la contención serializa
 * (espera y, con `lock_timeout`, falla por 55P03) pero nunca produce un
 * interbloqueo 40P01.
 *
 * Solo tiene sentido en PostgreSQL `systek_test`: SQLite no ofrece bloqueo
 * de fila real.
 */
final class ClauseLockOrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Prueba de concurrencia real: solo aplica a PostgreSQL (systek_test).');
        }
    }

    /** Ruta de `EloquentClauseRepository::lockWithCurrentVersion()` (usada por `PublishClauseVersion`). */
    private function publishClauseVersionLockPath(ConnectionInterface $connection, string $clauseId): void
    {
        $connection->table('clauses')->where('id', $clauseId)->lockForUpdate()->first();
        $connection->table('clause_versions')->where('clause_id', $clauseId)->where('status', 'current')->lockForUpdate()->first();
    }

    /** Ruta de `EloquentClauseRepository::lockedVersions()` (usada por `CreateQuote`/`ClauseSelection`). */
    private function createQuoteLockPath(ConnectionInterface $connection, string $clauseId, string $versionId): void
    {
        $connection->table('clauses')->where('id', $clauseId)->orderBy('id')->sharedLock()->get(['id']);
        $connection->table('clause_versions')->join('clauses', 'clauses.id', '=', 'clause_versions.clause_id')
            ->where('clause_versions.id', $versionId)->orderBy('clause_versions.id')->sharedLock()
            ->get(['clause_versions.id']);
    }

    public function test_publish_clause_version_and_create_quote_lock_paths_never_deadlock(): void
    {
        $baseConfig = config('database.connections.'.config('database.default'));
        foreach (['clause_lock_primary', 'clause_lock_secondary'] as $name) {
            config(["database.connections.{$name}" => $baseConfig]);
            TestDatabaseSafety::assertSafe(config("database.connections.{$name}"), app()->environment());
        }
        $primary = DB::connection('clause_lock_primary');
        $secondary = DB::connection('clause_lock_secondary');
        $secondary->statement("SET lock_timeout = '300ms'");

        $clauseId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $now = now();
        $primary->table('clauses')->insert([
            'id' => $clauseId, 'family' => 'cctv', 'type' => 'payment', 'title' => 'Contado (prueba de concurrencia)',
            'is_default' => false, 'active' => true, 'is_demo' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $primary->table('clause_versions')->insert([
            'id' => $versionId, 'clause_id' => $clauseId, 'version' => 1, 'status' => 'current',
            'body' => 'Contado.', 'body_hash' => hash('sha256', 'contado.'),
            'origin' => 'admin', 'reason' => 'Prueba de concurrencia', 'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $primary->transaction(function () use ($primary, $secondary, $clauseId, $versionId) {
                $this->publishClauseVersionLockPath($primary, $clauseId);

                try {
                    $secondary->transaction(fn () => $this->createQuoteLockPath($secondary, $clauseId, $versionId));
                } catch (QueryException $exception) {
                    // Contención esperada (misma fila, órdenes coherentes): puede
                    // agotar el lock_timeout (55P03), pero nunca debe ser un
                    // interbloqueo (40P01).
                    $message = strtolower($exception->getMessage());
                    $this->assertStringNotContainsString('deadlock', $message);
                    $this->assertStringNotContainsString('40p01', $message);
                }
            });

            // Tras el commit real de la primera conexión, la segunda ya no encuentra contención.
            $this->createQuoteLockPath($secondary, $clauseId, $versionId);
            $this->addToAssertionCount(1);
        } finally {
            $primary->table('clause_versions')->where('clause_id', $clauseId)->delete();
            $primary->table('clauses')->where('id', $clauseId)->delete();
            $primary->disconnect();
            $secondary->disconnect();
        }
    }
}
