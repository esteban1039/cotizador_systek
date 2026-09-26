# JARVIS Cotizador Systek

Aplicación local basada en el [plan maestro](plan_maestro_jarvis_cotizador_systek.md): Nuxt 4, Laravel 13 y PostgreSQL. Requiere Docker Compose y Node.js 22 o superior. No requiere PHP instalado en el host.

## Iniciar desde cero

```sh
cp backend/.env.example backend/.env
docker compose build api
docker compose run --rm api composer install --no-interaction
docker compose run --rm api php artisan key:generate
docker compose run --rm api php artisan migrate
docker compose run --rm api php artisan db:seed --class=DemoSeeder
docker compose run --rm api php artisan systek:create-admin --email=admin@systek.local
docker compose up -d
npm ci --prefix frontend
npm run dev --prefix frontend
```

Conserva `backend/.env` y la clave si ya existen. En una instalación existente basta ejecutar migraciones, el comando de creación de administrador si aún no existe y reiniciar servicios cuando corresponda.

Editor: **http://127.0.0.1:3003**. API: http://127.0.0.1:8000. Ambos escuchan solo en localhost. PostgreSQL no expone puerto al host. Las credenciales de Compose son exclusivamente de desarrollo.

## Acceso

El primer administrador se genera con una contraseña aleatoria, guardada únicamente en `backend/storage/app/private/local-admin-access.txt` (archivo ignorado por Git y con permisos 0600). No se muestra la contraseña en logs. Inicia sesión con esos datos y cambia la contraseña desde **Mi cuenta**. El comando no modifica administradores existentes. El archivo contiene la contraseña inicial y no se actualiza después del cambio.

El token compartido de las primeras iteraciones fue eliminado. Cada sesión utiliza un token Sanctum revocable con vencimiento de 8 horas, transportado por Nuxt en una cookie HttpOnly/SameSite Strict. El token no se expone a JavaScript, localStorage ni runtimeConfig.public. El proxy verifica el origen de las escrituras y limita las rutas permitidas. Cambiar contraseña, rol o estado revoca sesiones según corresponda.

| Rol | Alcance |
| --- | --- |
| Administrador | Clientes, catálogo, versiones de precio, usuarios, reglas, auditoría y acceso a todas las cotizaciones |
| Cotizador | Clientes, sedes, contactos y creación/consulta de sus propias cotizaciones; sin costos internos |
| Aprobador | Consulta de cotizaciones y revisión de propuestas creadas por otra persona |

Nadie puede aprobar su propia cotización. Los cambios de acceso se serializan para evitar que dos administradores se desactiven mutuamente. No existe registro público de usuarios ni recuperación de contraseña por correo en esta etapa.

## Módulos disponibles

- **Inicio:** tablero con borradores, revisiones pendientes, aprobaciones internas y vigencias vencidas; cada usuario ve solo el alcance permitido por su rol.
- **PWA:** manifiesto e iconos de instalación, ayuda para Android/iPhone y aviso de desconexión. Requiere conexión para consultar o guardar.
- **Editor:** cliente/sede, partidas, cantidades, descuentos, alcance, exclusiones, pago, garantía y vigencia. Vista previa y guardado con cálculo Laravel.
- **Borradores:** lista paginada y consulta de la copia guardada, con estado e historial de revisión.
- **Versiones:** crear una nueva revisión desde una cotización existente, precargar y corregir sus datos, conservar el original y navegar el historial. Cada versión empieza como borrador y exige su propia aprobación; los precios históricos requieren reemplazo explícito.
- **Clientes:** creación de clientes o prospectos, NIT normalizado y detección de duplicados exactos, sedes y contactos separados. No fusiona automáticamente.
- **Catálogo:** creación de ítems, activar/desactivar, publicar precio/costo/impuesto/vigencia con motivo. Publicar conserva los valores antiguos y marca las versiones anteriores como históricas; no modifica cotizaciones guardadas.
- **Históricos (administrador):** importar JSON con previsualización, conservar referencias duplicadas y aprobar/rechazar antecedentes con revisión humana. La aprobación exige seleccionar un cliente existente y nunca convierte precios históricos en vigentes.
- **MFA configurable por rol:** activación desde Mi cuenta con una aplicación autenticadora, diez códigos de recuperación de un uso y revocación de sesiones al activar/desactivar. La política `MFA_REQUIRED_ROLES=admin,approver` puede exigirlo a esos roles; mientras falte configurarlo solo se permite acceder a la cuenta. Por defecto sigue siendo opcional en desarrollo.
- **Usuarios:** creación, rol y activación; no se permite alterar el propio acceso.
- **Reglas comerciales:** margen mínimo, descuento máximo y umbral de monto por familia, ingresados por el administrador. No se sembraron valores oficiales inventados.
- **Revisión:** borrador → en revisión → aprobado internamente, o devolución a borrador. Recalcula antes de aprobar; bloquea precio vencido/histórico, totales inconsistentes, cotización vencida y reglas faltantes. El revisor ve las excepciones y deja una justificación.
- **PDF de borrador:** descarga autenticada de la versión guardada, con avisos en todas las páginas, sin costos ni márgenes. No cambia el estado ni habilita emisión oficial.
- **Auditoría:** registro de acceso, cambios administrativos, publicación de precios y revisiones.

La aprobación interna **no habilita emitir o compartir**. Todavía faltan datos bancarios (los ingresa el administrador), validación legal de las cláusulas, emisión definitiva de PDF, activación de la política MFA para el piloto y preparación de producción. Los borradores anteriores a usuarios no tienen autor: solo administradores/aprobadores pueden consultarlos y pueden utilizarse como origen de una nueva revisión identificada para llevarlos a aprobación.

## Dinero y demostración

El dinero se almacena en centavos enteros; las respuestas monetarias usan cadenas decimales. No se calcula dinero con float. Cantidades hasta 3 decimales, descuentos/impuestos en puntos básicos (1000 = 10 %). Redondeo half-up por partida: bruto, descuento, impuesto. El frontend solo formatea montos del servidor.

**Probar ejemplo CCTV** carga ocho cámaras ficticias: subtotal `1600000.00`, impuesto `304000.00`, total `1904000.00` COP. El 19 % prueba el cálculo, no determina el tratamiento tributario real. DemoSeeder solo funciona en local/testing y no renueva precios existentes al repetirse. No se importaron datos desde Drive.

La nueva lista de precios solo incluye versiones aprobadas vigentes e ítems activos. Al publicar una versión, los borradores que referencien la anterior requieren una nueva revisión con precios vigentes para aprobación. Cada guardado crea una instantánea nueva; no se sobrescriben versiones y todavía no hay idempotencia de escrituras.

## API v1

Inicio de sesión: `POST /api/v1/auth/login` con correo y contraseña; cuando MFA está activado requiere también `code` (TOTP o recuperación); resto de rutas requiere Bearer token individual. La aplicación web utiliza `/api/backend/...` y su cookie de sesión, sin manejar el Bearer en el navegador.

| Método | Ruta | Acción |
| --- | --- | --- |
| GET | `/admin/history`, `/admin/history/{id}` | Bandeja y detalle de antecedentes |
| POST | `/admin/history/import`, `/admin/history/{id}/review` | Importar lote JSON / decidir revisión humana |
| GET | `/dashboard` | Resumen de estados y cinco pendientes recientes visibles |
| POST | `/quotes/{id}/issue` | Emitir una cotización aprobada (archiva PDF oficial) |
| GET | `/quotes/{id}/official-pdf` | Descargar el PDF oficial archivado |
| GET / POST | `/admin/company` | Empresa emisora versionada (cuenta bancaria solo escritura) |
| GET / POST / PATCH | `/admin/clauses`, `/admin/clauses/{id}`, `/admin/clauses/{id}/versions` | Cláusulas por familia versionadas |
| GET | `/clauses?family=` | Cláusulas vigentes para cotizar |
| PATCH | `/clients/{id}/tax-profile` | Indicador de agente retenedor (ReteIVA), solo administrador |
| GET / POST | `/clients` | Consultar / crear clientes |
| POST | `/clients/{id}/sites`, `/clients/{id}/contacts` | Añadir sedes/contactos |
| GET | `/catalog` | Catálogo vigente para cotizar |
| GET / POST | `/admin/catalog` | Consultar historial / crear ítem |
| POST | `/admin/catalog/{id}/prices` | Publicar una nueva versión |
| PATCH | `/admin/catalog/{id}/active` | Activar/desactivar ítem |
| GET / POST | `/quotes` | Listar / guardar cotización |
| POST | `/quotes/preview` | Calcular sin guardar |
| GET | `/quotes/{id}` | Detalle, permisos e historial |
| GET | `/quotes/{id}/pdf` | Descargar PDF interno de la versión guardada |
| POST | `/quotes/{id}/revisions` | Crear nueva revisión con los campos completos del editor |
| POST | `/quotes/{id}/submit`, `/quotes/{id}/review` | Solicitar / decidir revisión |
| GET / POST | `/users`, `/rules` | Consultar / administrar |
| PATCH | `/users/{id}` | Cambiar rol/estado |
| GET | `/audit`, `/auth/me` | Auditoría / cuenta actual |
| GET | `/auth/mfa` | Estado de MFA y cantidad de códigos disponibles |
| POST | `/auth/mfa/setup`, `/auth/mfa/confirm`, `/auth/mfa/disable` | Preparar / activar / desactivar MFA |
| POST | `/auth/logout`, `/auth/password` | Cerrar sesión / cambiar contraseña |

Las rutas de la tabla se agregan a `/api/v1`. Los permisos se verifican en servidor.

## Verificación

```sh
docker compose run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test --compact
docker compose run --rm api vendor/bin/pint --test
npm run typecheck --prefix frontend
npm run build --prefix frontend
frontend/node_modules/.bin/playwright install chromium
docker compose -f compose.e2e.yaml up -d
docker compose -f compose.e2e.yaml exec -T api php artisan migrate --force
docker compose -f compose.e2e.yaml exec -T api php artisan db:seed --class=DemoSeeder --force
docker compose -f compose.e2e.yaml exec -T api php artisan systek:create-e2e-admin --file=e2e-isolated-access.json
npm run test:e2e --prefix frontend
```

La suite Laravel debe ejecutarse con SQLite en memoria explícito, como en el comando anterior. Una guardia comprueba la conexión resuelta antes de cualquier migración y bloquea otras bases salvo `systek_test`. No ejecutes pruebas sobre la base de desarrollo. Para probar PostgreSQL, crea **una sola vez** una base exclusiva:

```sh
docker compose exec -T postgres createdb -U systek systek_test
docker compose run --rm -e DB_CONNECTION=pgsql -e DB_DATABASE=systek_test api php artisan test --compact
```

No uses la base de desarrollo para PHPUnit: `RefreshDatabase` reconstruye el esquema. Playwright utiliza exclusivamente el entorno `compose.e2e.yaml`: PostgreSQL `systek_e2e` con volumen independiente, API en `127.0.0.1:8002` y frontend compilado en `127.0.0.1:3001`. Ejecuta `systek:create-e2e-admin --file=e2e-isolated-access.json` una sola vez en ese entorno; guarda un acceso exclusivo en `backend/storage/app/private/e2e-isolated-access.json`, con permisos 0600 e ignorado por Git. No cambia cuentas existentes ni sobrescribe archivos. El helper también admite `E2E_CREDENTIALS_PATH` para un JSON privado con `email` y `password`. Las pruebas crean registros ficticios etiquetados únicamente en esa base. Los trazados, videos, capturas automáticas y snapshots de error están deshabilitados; algunas pruebas capturan pantallas posteriores al acceso explícitamente. El servidor de pruebas no reutiliza servidores existentes: si 3001 está ocupado, la ejecución se detiene.

El editor de desarrollo usa 3003 para no competir con otro proyecto Docker que ocupa 3000.

Ejecuta build y navegador secuencialmente. Laravel arranca con `--no-reload` para conservar las variables PostgreSQL de Docker. Si cambias `backend/.env`, usa `docker compose restart api`.

## Pendiente

Envío al cliente con confirmación, aplicación de la migración de emisión en desarrollo, validación legal de cláusulas y datos bancarios, configuración de impuestos con contabilidad, activación operativa de MFA obligatorio en el piloto, asistente IA (la importación de Drive se descartó) y seguimiento/envío con confirmación. No hay operación sin conexión ni integración externa activa.

Las decisiones históricas están en `docs/primera-iteracion.md` y `docs/segunda-iteracion.md`; este README describe el estado actual.

La arquitectura y las convenciones del patrón repositorio se describen en [docs/arquitectura.md](docs/arquitectura.md).

El flujo de revisiones y sus límites se describen en [docs/cuarta-iteracion.md](docs/cuarta-iteracion.md).

El PDF interno se documenta en [docs/quinta-iteracion.md](docs/quinta-iteracion.md).

La instalación y el comportamiento sin conexión están en [docs/sexta-iteracion.md](docs/sexta-iteracion.md).

El tablero y las pruebas con acceso independiente se documentan en [docs/septima-iteracion.md](docs/septima-iteracion.md).

La configuración MFA y la copia privada de la base local se describen en [docs/octava-iteracion.md](docs/octava-iteracion.md).

La importación JSON y sus límites se describen en [docs/novena-iteracion.md](docs/novena-iteracion.md). La conexión directa con Drive permanece pendiente.

La política de MFA por rol se documenta en [docs/decima-iteracion.md](docs/decima-iteracion.md).

La base de la emisión (empresa, cláusulas, numeración y ReteIVA) se documenta en [docs/undecima-iteracion.md](docs/undecima-iteracion.md).

La emisión oficial se documenta en [docs/duodecima-iteracion.md](docs/duodecima-iteracion.md).
