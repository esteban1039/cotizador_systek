<?php

namespace App\Console\Commands;

use App\Domain\Audit;
use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateAdmin extends Command
{
    protected $signature = 'systek:create-admin {--email=admin@systek.local}';

    protected $description = 'Crea el primer administrador (solo si no existe ninguno) y guarda sus credenciales en un archivo privado.';

    public function __construct(private IdentityRepository $identities)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment(['local', 'production'])) {
            $this->error('Este comando solo está disponible en desarrollo local y en el primer arranque de producción.');

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
        $file = app()->isProduction() ? 'production-admin-access.txt' : 'local-admin-access.txt';
        $path = storage_path('app/private/'.$file);
        file_put_contents($path, "Acceso a JARVIS\nCorreo: {$email}\nContraseña: {$password}\nCambia esta contraseña desde Mi cuenta en tu primer ingreso.\n");
        chmod($path, 0600);
        $this->info("Administrador creado. Credenciales en storage/app/private/{$file}");
        if (app()->isProduction()) {
            $this->warn('Léelas una sola vez, bórralas del servidor y cambia la contraseña en tu primer ingreso.');
        }

        return self::SUCCESS;
    }
}
