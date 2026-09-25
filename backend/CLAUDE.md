# Backend Laravel — reglas específicas

Recortado de las guías de Laravel Boost (el MCP `laravel-boost` está desactivado). Si se ejecuta `php artisan boost:install|update`, revisar que no reponga el bloque completo. Todo comando PHP va por Docker desde la raíz: `docker compose run --rm api <comando>`.

## PHP
- Llaves siempre, también en cuerpos de una línea.
- Promoción de propiedades en el constructor; sin `__construct()` vacíos salvo privados.
- Tipos de retorno y de parámetros explícitos en todos los métodos.
- Claves de Enum en TitleCase.
- PHPDoc (con array shapes) en lugar de comentarios en línea; comentarios en línea solo para lógica excepcional.

## Laravel
- Antes de usar la API de un paquete, confirma la versión instalada (`composer show <paquete>` o `composer.lock`); no asumas versiones.
- Crear archivos con `php artisan make:<tipo> --no-interaction` (clases genéricas: `make:class`). Modelos nuevos con factory.
- APIs con Eloquent API Resources y versionado, salvo que las rutas existentes sigan otra convención.
- Enlaces con rutas nombradas y `route()`.
- Rutas: `php artisan route:list --path=api`; configuración: `php artisan config:show <clave>`.
- Tinker solo para depurar, con comillas simples (`php artisan tinker --execute '...'`); no crear modelos sin aprobación. Preferir pruebas con factories a scripts de verificación.

## Pruebas (PHPUnit, no Pest)
- `php artisan make:test --phpunit <Nombre>` (sin el directorio de la suite; `--unit` para unitarias). Mayoría Feature.
- Usar factories y sus estados; seguir la convención existente de `fake()` / `$this->faker`.
- Ejecutar el conjunto más estrecho que cubra el cambio (`--compact <archivo>` o `--filter`), siempre con la base explícita (ver `CLAUDE.md` raíz).
- Guía de cobertura, nombres y estructura: skill `testing-best-practices`. Buenas prácticas de Laravel: skill `laravel-best-practices`.

## Estilo
- Pint: el hook de Stop corre `pint --test` sobre los archivos modificados; corregir con `docker compose run --rm api vendor/bin/pint <archivos>`.
