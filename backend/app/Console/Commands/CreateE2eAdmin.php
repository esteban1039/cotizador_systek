<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreateE2eAdmin extends Command
{
    protected $signature = 'systek:create-e2e-admin {--file=e2e-admin-access.json : Nombre del archivo dentro de storage/app/private}';

    protected $description = 'Crea una cuenta E2E independiente en local/testing sin modificar usuarios existentes.';

    public function __construct(private IdentityRepository $identities)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Este comando solo está disponible en local/testing.');

            return self::FAILURE;
        }
        $filename = (string) $this->option('file');
        if (! preg_match('/\Ae2e-[a-zA-Z0-9_-]+\.json\z/', $filename)) {
            $this->error('Usa un nombre e2e-*.json sin directorios.');

            return self::FAILURE;
        }
        $path = storage_path('app/private/'.$filename);
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            $this->error('El archivo ya existe o no se puede crear. No se modificó ninguna cuenta.');

            return self::FAILURE;
        }
        try {
            $this->identities->transaction(function () use ($handle): void {
                $email = 'e2e-'.Str::uuid().'@example.test';
                $password = Str::password(32, symbols: false);
                $this->identities->create('Administrador E2E', $email, $password, 'admin');
                $payload = json_encode(compact('email', 'password'), JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $payload) !== strlen($payload) || ! fflush($handle)) {
                    throw new RuntimeException('No se pudo guardar el acceso E2E.');
                }
            });
        } catch (Throwable) {
            fclose($handle);
            unlink($path);
            $this->error('No se pudo provisionar la cuenta E2E.');

            return self::FAILURE;
        }
        fclose($handle);
        $this->info('Cuenta E2E creada. Archivo privado: storage/app/private/'.$filename);

        return self::SUCCESS;
    }
}
