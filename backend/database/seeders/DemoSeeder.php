<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Los ejemplos solo se cargan en entornos local/testing.');
        }
        DB::transaction(function () {
            $timestamps = ['created_at' => now(), 'updated_at' => now()];
            DB::table('clients')->insertOrIgnore(array_merge($timestamps, [
                'id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Cliente de demostración', 'is_demo' => true,
            ]));
            DB::table('sites')->insertOrIgnore(array_merge($timestamps, [
                'id' => '00000000-0000-4000-8000-000000000002', 'client_id' => '00000000-0000-4000-8000-000000000001',
                'name' => 'Sede de prueba', 'city' => 'Medellín',
            ]));
            DB::table('catalog_items')->insertOrIgnore(array_merge($timestamps, [
                'id' => '00000000-0000-4000-8000-000000000003', 'sku' => 'DEMO-CAM-IP',
                'description' => 'Cámara IP — ejemplo sin validez comercial', 'family' => 'cctv', 'unit' => 'unidad', 'active' => true, 'is_demo' => true,
            ]));
            DB::table('price_versions')->insertOrIgnore(array_merge($timestamps, [
                'id' => '00000000-0000-4000-8000-000000000004', 'catalog_item_id' => '00000000-0000-4000-8000-000000000003',
                'version' => 1, 'price_cents' => 20000000, 'cost_cents' => 15000000, 'tax_bps' => 1900,
                'valid_from' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(), 'status' => 'approved',
            ]));
        });
    }
}
