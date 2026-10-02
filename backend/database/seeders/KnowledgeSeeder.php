<?php

namespace Database\Seeders;

use App\Application\Quotes\ImportKnowledge;
use Illuminate\Database\Seeder;

/**
 * Puebla la base de conocimiento del asistente con las cotizaciones históricas de Drive
 * (database/seeders/data/knowledge_drive.psv) y con las cotizaciones aprobadas existentes.
 * Idempotente y ejecutable en cualquier entorno (no requiere un administrador):
 *
 *   php artisan db:seed --class=KnowledgeSeeder --force
 *
 * El precio de cada línea es solo referencia interna: no crea versiones de precio ni toca el catálogo.
 * No pisa la curación de un administrador (estado revisado/excluido).
 */
final class KnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/knowledge_drive.psv');
        if (is_file($path)) {
            $summary = app(ImportKnowledge::class)->handle($path, false, null);
            $this->command?->info('Drive: '.json_encode($summary));
        }

        $this->command?->call('systek:backfill-knowledge');
    }
}
