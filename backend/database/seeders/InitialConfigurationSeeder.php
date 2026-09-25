<?php

namespace Database\Seeders;

use App\Application\Company\PublishCompanyProfile;
use App\Application\Quotes\CreateClause;
use App\Repositories\Contracts\ClauseRepository;
use App\Repositories\Contracts\CompanyRepository;
use Illuminate\Database\Seeder;

/**
 * Carga la configuración inicial de Systek (empresa emisora + cláusulas
 * propuestas por familia). Idempotente y ejecutable en cualquier entorno:
 *
 *   php artisan db:seed --class=InitialConfigurationSeeder --force
 *
 * Nunca sobrescribe ediciones del administrador: si ya existe cualquier
 * versión de la empresa, o una cláusula con el mismo (family, type, title),
 * los deja intactos.
 */
final class InitialConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCompany();
        $this->seedClauses();
    }

    private function seedCompany(): void
    {
        $companies = app(CompanyRepository::class);
        if ($companies->hasAnyVersion()) {
            return;
        }

        app(PublishCompanyProfile::class)->execute([
            'legal_name' => 'Systek Company S.A.S.',
            'trade_name' => null,
            'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín',
            'phone' => '3045869886',
            'email' => 'stip@systekcompany.io',
            'website' => 'https://systekcompany.io',
            'signer_name' => 'Jhonatan Stip Gutierrez',
            'signer_title' => 'Gerente',
            'reason' => 'Carga inicial de datos oficiales entregados por Systek',
        ], null, 'initial_load');
    }

    private function seedClauses(): void
    {
        $path = database_path('seeders/data/initial_clauses.php');
        if (! file_exists($path)) {
            return;
        }

        /** @var list<array<string, mixed>> $definitions */
        $definitions = require $path;
        $clauses = app(ClauseRepository::class);
        $createClause = app(CreateClause::class);

        foreach ($definitions as $definition) {
            if ($clauses->titleExists($definition['family'], $definition['type'], $definition['title'])) {
                continue;
            }
            $createClause->execute([
                'family' => $definition['family'],
                'type' => $definition['type'],
                'title' => $definition['title'],
                'body' => $definition['body'],
                'is_default' => $definition['is_default'] ?? false,
                'reason' => 'Redacción inicial propuesta',
            ], null, 'initial_draft');
        }
    }
}
