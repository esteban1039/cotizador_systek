<?php

namespace App\Console\Commands;

use App\Application\Quotes\BackfillKnowledge;
use Illuminate\Console\Command;

final class BackfillKnowledgeCommand extends Command
{
    protected $signature = 'systek:backfill-knowledge {--dry-run}';

    protected $description = 'Carga las cotizaciones aprobadas existentes en la base de conocimiento (idempotente).';

    public function handle(BackfillKnowledge $backfill): int
    {
        $summary = $backfill->handle((bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? '[dry-run] ' : '').json_encode($summary));

        return self::SUCCESS;
    }
}
