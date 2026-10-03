<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Crea los clientes de Systek extraídos de la carpeta CLIENTES de Drive
 * (database/seeders/data/clients.csv: name, nit). Idempotente y ejecutable en cualquier entorno:
 *
 *   php artisan db:seed --class=ClientSeeder --force
 *
 * Reglas: nombre obligatorio, NIT opcional (sin dígito de verificación). Se omite el cliente si ya
 * existe el mismo NIT o el mismo nombre (sin distinguir mayúsculas); nada se sobrescribe.
 * No crea sedes ni contactos.
 */
final class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/clients.csv');
        if (! is_file($path)) {
            return;
        }

        $created = 0;
        $skipped = 0;
        $handle = fopen($path, 'rb');
        fgetcsv($handle, escape: '');
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $name = trim((string) ($row[0] ?? ''));
            $nit = trim((string) ($row[1] ?? ''));
            if ($name === '') {
                continue;
            }
            $nit = $nit === '' ? null : $nit;

            $exists = Client::query()
                ->when($nit !== null, fn ($q) => $q->where('nit', $nit)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))
                ->when($nit === null, fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($name)]))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            Client::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'nit' => $nit,
                'is_demo' => false,
            ]);
            $created++;
        }
        fclose($handle);

        $this->command?->info("Clientes: creados {$created}, omitidos {$skipped}");
    }
}
