<?php

namespace App\Console\Commands;

use App\Domain\Audit;
use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateAdmin extends Command
{
    protected $signature = 'systek:create-admin {--email=admin@systek.local}';

    protected $description = 'Crea el primer administrador local y guarda sus credenciales en un archivo privado.';

    public function __construct(private IdentityRepository $identities)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo está disponible en desarrollo local.');

            return self::FAILURE;
        }
        if ($this->identities->hasAdministrator()) {
            $this->info('Ya existe un administrador. No se modificó su cuenta.');

            return self::SUCCESS;
        }
        $email = strtolower($this->option('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->identities->emailExists($email)) {
            $this->error('Correo inválido o ya registrado.');

            return self::FAILURE;
        }
        $password = Str::password(24, symbols: false);
        $user = $this->identities->create('Administrador Systek', $email, $password, 'admin');
        Audit::record($user->id, 'admin.bootstrapped', (string) $user->id);
        $path = storage_path('app/private/local-admin-access.txt');
        file_put_contents($path, "Acceso local a JARVIS\nCorreo: {$email}\nContraseña: {$password}\nCambia esta contraseña desde Mi cuenta.\n");
        chmod($path, 0600);
        $this->info('Administrador creado. Credenciales en storage/app/private/local-admin-access.txt');

        return self::SUCCESS;
    }
}
