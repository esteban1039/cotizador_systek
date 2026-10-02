<?php

namespace App\Console\Commands;

use App\Application\Quotes\EvaluateKnowledge;
use Illuminate\Console\Command;

final class EvalKnowledgeCommand extends Command
{
    protected $signature = 'systek:eval-knowledge {--k=4}';

    protected $description = 'Mide hit@k de la recuperación sobre cotizaciones aprobadas (offline, sin llamar al proveedor).';

    public function handle(EvaluateKnowledge $evaluate): int
    {
        $result = $evaluate->handle(max(1, (int) $this->option('k')));
        $this->info(json_encode($result));
        if ($result['evaluated'] === 0) {
            $this->line('Sin cotizaciones aprobadas con SKU: hit@k = 0.');
        }

        return self::SUCCESS;
    }
}
