<?php

namespace Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Concurrencia real del contador de numeración (misma lógica que
 * `EloquentQuoteNumberRepository::allocate()`). Solo tiene sentido en
 * PostgreSQL `systek_test`: SQLite no ofrece bloqueo de fila real
 * (`lockForUpdate` se ignora), así que se omite ahí.
 *
 * Usa dos conexiones nombradas explícitas (ninguna es la conexión por
 * defecto) porque `RefreshDatabase` envuelve la conexión por defecto en una
 * transacción por prueba que nunca hace commit real (solo rollback al
 * final): "confirmar" esa transacción no liberaría los bloqueos de fila
 * frente a otra conexión real.
 */
final class QuoteNumberConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Prueba de concurrencia real: solo aplica a PostgreSQL (systek_test).');
        }
    }

    private function allocate(ConnectionInterface $connection, int $year): int
    {
        $connection->table('quote_number_sequences')->insertOrIgnore([
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $current = (int) $connection->table('quote_number_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
        $next = $current + 1;
        $connection->table('quote_number_sequences')->where('year', $year)->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }

    public function test_a_second_connection_waits_for_the_counters_lock_then_gets_the_next_number(): void
    {
        // Año fuera del rango usado por el resto de la suite (2025-2027, ver
        // `travelTo` en las demás pruebas de numeración): esta prueba confirma
        // commits reales en connections independientes de `RefreshDatabase`,
        // así que la fila que crea sobrevive al rollback por prueba y no debe
        // colisionar con los números que otras pruebas esperan.
        $year = 2999;

        $baseConfig = config('database.connections.'.config('database.default'));
        foreach (['quote_number_primary', 'quote_number_secondary'] as $name) {
            config(["database.connections.{$name}" => $baseConfig]);
            TestDatabaseSafety::assertSafe(config("database.connections.{$name}"), app()->environment());
        }
        $primary = DB::connection('quote_number_primary');
        $secondary = DB::connection('quote_number_secondary');
        $secondary->statement("SET lock_timeout = '200ms'");

        try {
            $primary->transaction(function () use ($primary, $secondary, $year) {
                $first = $this->allocate($primary, $year);
                $this->assertSame(1, $first);

                try {
                    $secondary->transaction(fn () => $this->allocate($secondary, $year));
                    $this->fail('Se esperaba que la segunda conexión esperara y fallara por lock_timeout mientras la primera retiene el contador.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsStringIgnoringCase('lock', $exception->getMessage());
                }
            });

            // Tras el commit real de la primera conexión, la segunda asigna el siguiente número.
            $second = $secondary->transaction(fn () => $this->allocate($secondary, $year));
            $this->assertSame(2, $second);
        } finally {
            // Limpieza: estos commits son reales y no los revierte `RefreshDatabase`.
            $secondary->table('quote_number_sequences')->where('year', $year)->delete();
            $primary->disconnect();
            $secondary->disconnect();
        }
    }
}
