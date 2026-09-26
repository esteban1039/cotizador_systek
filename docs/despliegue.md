# Despliegue: backend en Laravel Forge, frontend en Cloudflare

Arquitectura: el navegador solo habla con el frontend (Cloudflare Workers, Nuxt/Nitro). El servidor del frontend (BFF) valida ruta, método y origen, guarda el token en la cookie `systek_session` (HttpOnly, `SameSite=Strict`, `Secure`) y llama a la API de Forge con un **secreto compartido**. La API nunca se usa desde el navegador.

Los valores entre `<...>` los defines tú. Nada de lo siguiente se ejecutó contra Forge ni Cloudflare: es la guía para hacerlo con tus cuentas.

## 0. Antes de empezar

- **Dominio** con dos nombres: `app.<dominio>` (frontend) y `api.<dominio>` (backend).
- **PHP en Forge:** `composer.json` exige `^8.3` (Laravel 13). Las pruebas se ejecutaron en PHP 8.5 (Docker). Elige en Forge la versión más alta disponible ≥ 8.3 y comprueba en el servidor `composer check-platform-reqs` y `php artisan test` (SQLite en memoria) antes de cargar datos. Extensiones necesarias: `pdo_pgsql`, `mbstring`, `bcmath`, `intl`, `zip`, `gd` (dompdf).
- **Respalda `APP_KEY`** fuera del servidor (gestor de secretos y copia física). Cifra la cuenta bancaria, los PDF oficiales y los secretos de MFA: perderla hace esos datos irrecuperables aunque exista el respaldo de la base.

## 1. Backend en Forge

1. **Servidor y sitio:** crea el servidor con PostgreSQL. Sitio `api.<dominio>`, repositorio `esteban1039/cotizador_systek`, rama `main`. El repositorio es un monorepo: **Root del sitio = `backend`, Web Directory = `/public`** (si el webroot apunta a la raíz del repo, se expone todo el código).
2. **Base de datos:** crea la base y un **usuario de aplicación** (no el superusuario). Idealmente, un usuario de migraciones aparte para el despliegue.
3. **Entorno:** copia `backend/.env.production.example` al entorno del sitio y completa `APP_KEY` (`php artisan key:generate --show`), la base, `BFF_SHARED_SECRET` (`openssl rand -hex 32`) y `APP_URL`. Con `BFF_SHARED_SECRET` vacío la API responde 503 en producción (falla cerrada); con valor, responde 404 a todo lo que no traiga la cabecera `X-BFF-Secret` correcta. `MFA_REQUIRED_ROLES` déjalo vacío hasta la sección 4. Permisos del archivo de entorno: 0600.
4. **HTTPS:** Let's Encrypt desde Forge, o certificado de origen de Cloudflare con modo SSL «Full (strict)».
5. **Script de despliegue (Forge → Deployment Script):**

```sh
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH
cd backend
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
# Respaldo verificado ANTES de migrar (aborta el despliegue si falla).
set -e
pg_dump --format=custom --file=/home/forge/backups/pre-deploy-$(date +%Y%m%dT%H%M%S).dump "$DB_DATABASE"
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan event:cache
( flock -w 10 9 || exit 1; echo 'Recargando PHP-FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
```

   Nunca uses `migrate:fresh`, `migrate:reset` ni `db:wipe`. El usuario de `pg_dump` necesita permiso de lectura; ajusta la ruta de respaldos (fuera del webroot).
6. **Tiempos y memoria:** el PDF y el asistente IA pueden tardar. Sube `fastcgi_read_timeout` de nginx y `request_terminate_timeout` de PHP-FPM a ≥ 60 s, `max_execution_time` ≥ 60 y `memory_limit` 256M.
7. **Respaldos automáticos (Forge → Backups):** diarios, destino fuera del servidor (S3 compatible), retención (por ejemplo 7 diarios, 4 semanales, 6 mensuales). **Prueba una restauración** a una base temporal y compara conteos antes de cargar datos reales. Los PDF viven en la base: vigila el tamaño de los dumps.
8. **Sin worker ni scheduler:** las colas son `sync` y no hay tareas programadas.
9. **Comprobación:** `https://api.<dominio>/up` debe responder 200 (la excepción del secreto es solo para `/api`); `https://api.<dominio>/api/v1/auth/me` sin cabecera debe responder **404**.

## 2. Frontend en Cloudflare Workers

1. **Compilar:** en `frontend/`, `NITRO_PRESET=cloudflare_module npm run build` (la compilación para Workers se verificó localmente sin dependencias nuevas). `frontend/wrangler.jsonc` ya trae el nombre, `nodejs_compat` y la carpeta de recursos.
2. **Desplegar:** `npx wrangler deploy` (login con tu cuenta de Cloudflare). También puedes conectar el repositorio (Workers Builds) con directorio raíz `frontend`, comando de compilación `NITRO_PRESET=cloudflare_module npm run build` y de despliegue `npx wrangler deploy`.
3. **Variables del Worker (panel de Cloudflare):**

| Variable | Valor | Tipo |
| --- | --- | --- |
| `NUXT_API_BASE` | `https://api.<dominio>/api/v1` | texto |
| `NUXT_ALLOWED_HOSTS` | `app.<dominio>` | texto |
| `NUXT_BFF_SECRET` | el mismo valor que `BFF_SHARED_SECRET` | **secreto** |

   Sin `NUXT_ALLOWED_HOSTS` el proxy responde 403 (solo admite localhost).
4. **Dominio:** asigna `app.<dominio>` al Worker (Custom Domain). La cookie es del host del frontend, sin `Domain`.
5. **Caché:** crea una regla que **no cachee** `/api/*` (el BFF ya responde `Cache-Control: no-store`); no actives «Cache Everything».
6. **Cabeceras:** el frontend ya envía `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` y HSTS. La **CSP** queda pendiente: Nuxt hidrata con scripts en línea y hay que probarla con la PWA antes de activarla.
7. **IP real:** el BFF reenvía `CF-Connecting-IP` como `X-Forwarded-For`; la API solo confía en esa cabecera cuando el secreto es válido, así el límite de intentos de login es por cliente y no global.

## 3. Protección extra de la API (recomendado)

Con el secreto compartido la API responde 404 a quien no lo tenga, pero sigue pública. Endurécela con una de estas opciones: firewall de Forge que acepte 443 solo desde los rangos de IP de Cloudflare (combinado con el secreto), o Cloudflare Tunnel con `cloudflared` en el servidor (elimina la exposición; es software nuevo y requiere tu aprobación).

## 4. Primer arranque y datos iniciales

1. `php artisan migrate --force` (ya lo hace el despliegue).
2. `php artisan db:seed --class=InitialConfigurationSeeder --force` (datos legales públicos y cláusulas iniciales; sin datos bancarios). **No** ejecutes `DemoSeeder` (falla a propósito fuera de local/testing).
3. `php artisan systek:create-admin --email=<correo>` (solo funciona si aún no existe ningún administrador; genera una contraseña de 24 caracteres y la guarda en un archivo privado del servidor con permisos 0600). Léela **una sola vez** desde la pestaña Commands de Forge (`cat` del archivo de acceso de producción y luego bórralo con `rm`), guárdala en un gestor, elimina esos dos registros del historial de Commands y cambia la contraseña en tu primer ingreso (Mi cuenta).
4. Entra por `https://app.<dominio>` y configura por la interfaz: **cuenta bancaria** (`/empresa`), **reglas comerciales** (`/reglas`), **usuarios** y aprobador (`/usuarios`), catálogo y precios.
5. **MFA:** cada administrador y aprobador configura su autenticador en «Mi cuenta» y guarda sus códigos de recuperación. Después define `MFA_REQUIRED_ROLES=admin,approver` en Forge y recarga la configuración (`php artisan config:cache`, reinicio de PHP-FPM).
6. Asistente IA (opcional): `ANTHROPIC_API_KEY` y `AI_ASSISTANT_ENABLED=true` en Forge; ver [decimocuarta-iteracion.md](decimocuarta-iteracion.md).

## 5. Lista de comprobación antes del piloto

- [ ] `APP_KEY` respaldada fuera del servidor.
- [ ] Restauración de un respaldo probada en una base temporal.
- [ ] `api.<dominio>/api/v1/auth/me` sin cabecera responde 404; con el frontend, el login funciona.
- [ ] Login: los intentos fallidos de un usuario no bloquean a los demás.
- [ ] Un PDF oficial se emite y se descarga (tamaño y tiempo aceptables); el asistente IA responde dentro del tiempo límite, si está activado.
- [ ] MFA obligatorio activo y códigos de recuperación guardados.
- [ ] Ensayo completo en un subdominio de pruebas antes de usar datos reales.

## 6. Pendiente

CSP; usuario de base de datos de solo lectura/escritura separado del de migraciones; monitor externo con alertas sobre `/up`; `sanctum:prune-expired` programado (opcional); versión de PostgreSQL fijada; límite global diario del asistente IA; validación en Cloudflare del comportamiento de tiempos largos (PDF 45 s, asistente 35 s) con las cuentas y el plan reales.
