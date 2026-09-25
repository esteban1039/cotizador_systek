<?php

namespace Tests\Unit;

use App\Domain\QuoteFamily;
use App\Domain\Quotes\ClauseText;
use App\Domain\Quotes\ClauseType;
use PHPUnit\Framework\TestCase;

/**
 * Integridad de la redacción inicial real cargada por
 * `InitialConfigurationSeeder` (diseño-iteracion-11.md §11: "Las 56
 * cláusulas reales no violan la regla de coherencia de texto idéntico entre
 * familias"). Prueba de datos, no de comportamiento HTTP: reutiliza las
 * mismas reglas de `ClauseText` y el mismo conjunto de tipos comparados que
 * `App\Domain\Quotes\ClauseCoherence::HASH_CHECKED_TYPES` (todos menos
 * `validity`), para detectar una regresión si alguien edita el archivo de
 * datos sin volver a correr las pruebas de integración.
 */
final class InitialClausesDataTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function clauses(): array
    {
        return require __DIR__.'/../../database/seeders/data/initial_clauses.php';
    }

    public function test_defines_exactly_56_clauses_covering_every_family_and_type_once_or_more(): void
    {
        $clauses = $this->clauses();
        $this->assertCount(56, $clauses);

        foreach ($clauses as $clause) {
            $this->assertContains($clause['family'], QuoteFamily::values());
            $this->assertContains($clause['type'], ClauseType::values());
        }

        // 7 familias x 8 cláusulas cada una (scope_base, exclusions, 3 de
        // payment, warranty, validity, observations).
        foreach (QuoteFamily::values() as $family) {
            $forFamily = array_filter($clauses, fn (array $c): bool => $c['family'] === $family);
            $this->assertCount(8, $forFamily, "La familia {$family} debe tener 8 cláusulas.");
        }
    }

    public function test_every_clause_body_passes_content_validation(): void
    {
        foreach ($this->clauses() as $clause) {
            $errors = ClauseText::validate($clause['body']);
            $this->assertSame([], $errors, "«{$clause['family']}/{$clause['type']}/{$clause['title']}» falló validación: ".implode(' ', $errors));
        }
    }

    public function test_every_clause_body_respects_its_type_max_length(): void
    {
        foreach ($this->clauses() as $clause) {
            $type = ClauseType::from($clause['type']);
            $this->assertLessThanOrEqual(
                $type->maxLength(),
                mb_strlen($clause['body']),
                "«{$clause['family']}/{$clause['type']}/{$clause['title']}» supera la longitud máxima de {$type->value}."
            );
        }
    }

    public function test_titles_are_unique_within_the_same_family_and_type(): void
    {
        $seen = [];
        foreach ($this->clauses() as $clause) {
            $key = $clause['family'].'|'.$clause['type'].'|'.$clause['title'];
            $this->assertArrayNotHasKey($key, $seen, "Título duplicado: {$key}");
            $seen[$key] = true;
        }
    }

    public function test_exactly_one_default_clause_per_family_and_type(): void
    {
        $byGroup = [];
        foreach ($this->clauses() as $clause) {
            $key = $clause['family'].'|'.$clause['type'];
            $byGroup[$key][] = (bool) ($clause['is_default'] ?? false);
        }

        foreach ($byGroup as $key => $defaults) {
            $this->assertSame(1, count(array_filter($defaults)), "El grupo {$key} debe tener exactamente una cláusula predeterminada.");
        }
    }

    /**
     * Reproduce la regla E4 de `ClauseCoherence`: ningún texto (para los
     * tipos comparados) puede coincidir, tras normalizar espacios y
     * mayúsculas, con el de una cláusula de otra familia.
     */
    public function test_no_clause_text_is_identical_to_a_clause_of_a_different_family(): void
    {
        $checkedTypes = [
            ClauseType::ScopeBase->value, ClauseType::Exclusions->value, ClauseType::Payment->value,
            ClauseType::Warranty->value, ClauseType::Observations->value,
        ];

        $byHash = [];
        foreach ($this->clauses() as $clause) {
            if (! in_array($clause['type'], $checkedTypes, true)) {
                continue;
            }
            $hash = ClauseText::hash($clause['body']);
            $byHash[$hash][] = $clause;
        }

        foreach ($byHash as $hash => $group) {
            $families = array_unique(array_column($group, 'family'));
            $this->assertCount(
                1,
                $families,
                'Texto idéntico entre familias ('.implode(', ', $families).'): «'.$group[0]['body'].'»'
            );
        }
    }
}
