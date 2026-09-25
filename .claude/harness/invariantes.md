# Invariantes del proyecto (lectura obligatoria para todo agente que modifique o revise código)

Fuente: código actual, `README.md`, `docs/arquitectura.md`, `backend/CLAUDE.md`. Si una invariante contradice el pedido, detente y repórtalo al orquestador.

## Dinero y cálculo
- Centavos enteros (`int`) en PHP; cantidades hasta 3 decimales; descuentos e impuestos en puntos básicos (1000 = 10 %).
- Redondeo half-up por partida: bruto → descuento → impuesto. Lógica en `app/Domain/Quotes/QuoteCalculator.php` y `app/Domain/DecimalMoney.php`.
- Respuestas monetarias como cadenas decimales. El frontend (`app/utils/format.ts`) solo formatea (BigInt/cadenas); nunca calcula totales.

## Precios, cotizaciones y aprobación
- Publicar precio crea una `PriceVersion` nueva y marca las anteriores como históricas. Nunca se modifican cotizaciones guardadas.
- Cada guardado crea una instantánea nueva; las revisiones crean una versión nueva en borrador con su propia aprobación.
- Estados: borrador → en revisión → aprobado internamente (o devuelto). Aprobar recalcula y bloquea precios vencidos/históricos, totales inconsistentes, cotización vencida y reglas faltantes.
- Nadie aprueba su propia cotización. La aprobación interna no habilita emitir ni compartir.
- Los costos internos y márgenes se ocultan por rol (`QuoteVisibility`). El PDF de borrador nunca muestra costos ni márgenes.
- Históricos importados nunca se convierten en precios vigentes.

## Arquitectura backend
- Controladores: validación HTTP (Form Requests), coordinación y respuesta. Sin consultas.
- `Application/*`: casos de uso; dueños de las transacciones que abarcan varios repositorios y la auditoría.
- `Domain/*`: reglas puras; obtienen datos solo vía contratos.
- `Repositories/Contracts` específicos por módulo; `Repositories/Eloquent` los implementa; se registran en `app/Providers/*RepositoryServiceProvider.php`. No hay repositorio CRUD genérico ni query builders expuestos.
- Bloqueos dentro de repositorios, invocados dentro de transacción, en orden: ítem → precio; usuario → tokens; cotización → estado.
- `tests/Feature/RepositoryArchitectureTest.php` vigila estas reglas: no lo debilites.

## Seguridad y acceso
- Roles: `admin`, `quoter`, `approver`; middleware `role:` + `ActiveUser` + `RequireMfaEnrollment` en `routes/api.php`. La autorización se verifica en servidor, también a nivel de registro (visibilidad de cotizaciones por autor/rol).
- Tokens Sanctum de 8 h. Cambiar contraseña, rol, estado o MFA revoca sesiones según corresponda.
- El proxy Nuxt `frontend/server/api/backend/[...path].ts` tiene allowlist por método, exige `Origin` igual y `application/json` en escrituras, limita importación a 1 MB y sanea errores. **Todo endpoint nuevo debe agregarse ahí de forma explícita y mínima.**
- Throttling existente en login, MFA e importación: mantenerlo en endpoints sensibles nuevos.
- Rutas de auth e históricos responden `Cache-Control: no-store`.
- Auditoría (`app/Domain/Audit.php`) para cambios administrativos, precios, revisiones y accesos.

## Datos y entornos
- PostgreSQL 17 en Docker. Base de desarrollo `systek` (no tocar en pruebas), pruebas `systek_test` o SQLite `:memory:`, E2E `systek_e2e` (`compose.e2e.yaml`).
- Migraciones: nuevas, reversibles, sin editar las aplicadas. Nada destructivo sin advertencia y aprobación humana explícita. Copia previa con `scripts/backup-local-db.sh`.
- `DemoSeeder` solo en local/testing; datos ficticios.

## Secretos
- No leer `.env`, `backend/storage/app/private/**` (contraseña inicial, accesos E2E, token de Drive, copias de base). Usa `.env.example` para conocer claves.
- No registrar contraseñas, tokens, secretos TOTP, códigos de recuperación ni datos bancarios.

## Convenciones
- PHP: `final class`, promoción de propiedades en constructor, tipos de retorno explícitos, llaves siempre, PHPDoc con array shapes, Pint.
- Tests PHPUnit (no Pest), preferentemente Feature; factories.
- Frontend: páginas en `app/pages`, componentes en `app/components`, composables en `app/composables`, tipos compartidos en `shared/`. Textos de UI en español. Sin dependencias nuevas sin aprobación.
