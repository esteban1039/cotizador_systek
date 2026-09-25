<?php

namespace App\Console\Commands;

use App\Application\Drive\DriveInventoryClient;
use Illuminate\Console\Command;
use Throwable;

final class InventoryDrive extends Command
{
    protected $signature = 'systek:inventory-drive {--max-files=2000} {--max-depth=10}';

    protected $description = 'Inventaría metadatos de una carpeta Drive sin descargar ni modificar archivos.';

    public function handle(DriveInventoryClient $client): int
    {
        $maxFiles = filter_var($this->option('max-files'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $maxDepth = filter_var($this->option('max-depth'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        if ($maxFiles === false || $maxDepth === false) {
            $this->error('Límites inválidos: max-files 1–10000, max-depth 1–30.');

            return self::FAILURE;
        }
        $temporary = null;
        try {
            $report = $client->inventory((string) config('drive.root_folder_id', ''), $maxFiles, $maxDepth);
            $directory = storage_path('app/private/drive-inventory');
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700, true))) {
                throw new \RuntimeException;
            }
            if (! @chmod($directory, 0700)) {
                throw new \RuntimeException;
            }
            $temporary = tempnam($directory, '.inventory-');
            if ($temporary === false || ! chmod($temporary, 0600)) {
                throw new \RuntimeException;
            }
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new \RuntimeException;
            }
            $target = $directory.'/inventory-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
            if (! rename($temporary, $target)) {
                throw new \RuntimeException;
            }
            $temporary = null;
            $this->line('Reporte privado: '.$target);
            $this->line('Estado: '.$report['status'].'; registros: '.$report['counts']['files']);
            if ($report['status'] !== 'complete') {
                $this->error('Inventario incompleto; revisa los códigos de incidencia en el reporte.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No fue posible generar el inventario privado. Verifica la configuración y permisos.');

            return self::FAILURE;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
