<?php

namespace App\Console\Commands;

use App\Application\Quotes\ImportKnowledge;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ImportKnowledgeCommand extends Command
{
    protected $signature = 'systek:import-knowledge {archivo} {--dry-run} {--as= : Correo del administrador que ejecuta}';

    protected $description = 'Carga el PSV de cotizaciones de Drive en la base de conocimiento del asistente (precio solo como referencia interna).';

    public function handle(ImportKnowledge $import): int
    {
        try {
            $summary = $import->handle((string) $this->argument('archivo'), (bool) $this->option('dry-run'), (string) $this->option('as'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info(($this->option('dry-run') ? '[dry-run] ' : '').json_encode($summary));

        return self::SUCCESS;
    }
}
