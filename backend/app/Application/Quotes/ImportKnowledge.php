<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\KnowledgeEntry;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Carga inicial de la base de conocimiento desde el PSV de Drive (docs/diseno-base-conocimiento-ia.md §8.5).
 * Una entrada por fuente. El precio es referencia interna: no crea PriceVersion ni toca el catálogo.
 * Auditoría solo con contadores. `source_ref` es un hash de la fuente (el nombre delata al cliente).
 */
final class ImportKnowledge
{
    private const MAX_BYTES = 5_000_000;

    public function __construct(private KnowledgeRepository $knowledge, private KnowledgeEntry $entries) {}

    /**
     * @return array<string, int>
     */
    public function handle(string $path, bool $dryRun, ?string $adminEmail): array
    {
        $adminId = $adminEmail === null ? null : $this->knowledge->activeAdminId($adminEmail);
        if ($adminEmail !== null && $adminId === null) {
            throw new InvalidArgumentException('El usuario indicado no es un administrador activo.');
        }
        if (! is_file($path) || ! is_readable($path) || filesize($path) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Archivo ilegible o mayor a 5 MB.');
        }

        $groups = [];
        $summary = ['groups' => 0, 'lines' => 0, 'skipped_lines' => 0, 'lines_without_price' => 0, 'usd_lines' => 0,
            'created' => 0, 'updated' => 0, 'unchanged' => 0, 'active' => 0, 'needs_review' => 0];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode('|', $line, 4);
            if (str_starts_with($line, '#') || count($parts) < 4 || strtolower(trim($parts[1])) === 'seccion' || trim($parts[3]) === '') {
                $summary['skipped_lines'] += (str_starts_with($line, '#') || strtolower(trim($parts[1] ?? '')) === 'seccion') ? 0 : 1;

                continue;
            }
            $groups[trim($parts[0])][] = ['section' => trim($parts[1]), 'price' => trim($parts[2]), 'description' => trim($parts[3])];
        }

        $process = function () use ($groups, $dryRun, &$summary): void {
            foreach ($groups as $source => $rows) {
                $segments = explode('-', $source);
                $names = array_values(array_unique([$segments[0], count($segments) > 1 ? $segments[0].' '.$segments[1] : null]));
                $entry = $this->entries->fromDriveGroup($names, $rows);
                $summary['groups']++;
                foreach ($entry['lines'] as $line) {
                    $summary['lines']++;
                    $summary['lines_without_price'] += $line['reference_price_cents'] === null ? 1 : 0;
                    $summary['usd_lines'] += $line['currency'] === 'USD' ? 1 : 0;
                }
                $summary[$entry['risk'] === 'review' ? 'needs_review' : 'active']++;
                if ($dryRun) {
                    continue;
                }
                $result = $this->knowledge->importDriveGroup($entry + [
                    'source' => 'drive_import', 'source_root_quote_id' => null, 'source_revision' => null,
                    'source_ref' => 'drive:'.substr(hash('sha256', $source), 0, 24), 'issued' => false, 'trust' => '0.50',
                ]);
                $summary[$result]++;
            }
        };
        if ($dryRun) {
            $process();

            return $summary;
        }
        DB::transaction(function () use ($process): void {
            $process();
        });
        Audit::record($adminId, 'knowledge.imported', 'drive_import', $summary);

        return $summary;
    }
}
