# JARVIS Cotizador Systek

Cotizador comercial interno (PWA) de Systek: clientes/sedes/contactos, catálogo con versiones de precio, cotizaciones con revisiones, aprobación humana, PDF de borrador, históricos, MFA y auditoría. Estado y alcance: `README.md`, `plan_maestro_jarvis_cotizador_systek.md`, `docs/*-iteracion.md`.

## Arquitectura

- `frontend/` — Nuxt 4 + Vue 3 + TS, CSS propio (`app/assets/css`), sin librería de UI. `server/api/backend/[...path].ts` es un proxy BFF con **allowlist por método**, verificación de origen y cookie HttpOnly `systek_session`; el navegador nunca ve el token.
- `backend/` — Laravel 13, PHP 8.5 (solo en Docker), Sanctum (8 h), google2fa, dompdf. API `/api/v1`. Capas: `Http/Controllers` → `Application/*` (casos de uso, transacciones) → `Domain/*` → `Repositories/Contracts` + `Repositories/Eloquent`. Detalle: `docs/arquitectura.md`.
- PostgreSQL 17 en Docker (sin puerto al host); caché/sesión en archivo, colas `sync`. Drive: solo inventario de metadatos (desactivado).
- Mapa: `backend/{app,routes/api.php,database/migrations,tests}`, `frontend/{app/pages,app/components,app/composables,server,shared,tests}`.

## Comandos (desde la raíz; PHP solo vía Docker)

| Acción | Comando |
| --- | --- |
| Levantar | `docker compose up -d` y `npm run dev --prefix frontend` (3003) |
| Tests backend | `docker compose run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test --compact [archivo\|--filter=x]` |
| Tests PostgreSQL | igual con `-e DB_CONNECTION=pgsql -e DB_DATABASE=systek_test` |
| Estilo PHP | `docker compose run --rm api vendor/bin/pint --test` (corregir: sin `--test`) |
| Frontend | `npm run typecheck --prefix frontend` · `npm run build --prefix frontend` |
| E2E | `README.md` § Verificación (`compose.e2e.yaml`, base `systek_e2e`) |
| Copia de la base | `scripts/backup-local-db.sh` |

El hook de Stop ya corre Pint, typecheck y las pruebas PHP modificadas; no repetirlos a mano salvo para depurar.

## Reglas no negociables (detalle en `.claude/harness/invariantes.md`)

- **Dinero:** centavos enteros, nunca float; respuestas como cadenas decimales; el frontend solo formatea.
- **Precios:** publicar crea versión nueva; históricos nunca pasan a vigentes; no se reescriben cotizaciones guardadas ni instantáneas.
- **Emisión:** sin emisión, envío ni PDF oficial sin aprobación humana; nadie aprueba su propia cotización.
- **Capas:** sin consultas en controladores ni dominio; contratos específicos; transacciones en el caso de uso; bloqueos ítem→precio, usuario→tokens, cotización→estado.
- **Autorización en servidor.** Endpoint nuevo = ruta en `backend/routes/api.php` **y** entrada en la allowlist del proxy.
- **BD:** migraciones nuevas y reversibles; nunca editar aplicadas; prohibido `migrate:fresh|reset`, `db:wipe`, `DROP`, `TRUNCATE` o borrar volúmenes; respaldo antes de migrar desarrollo.
- **Pruebas:** solo SQLite `:memory:` o `systek_test`; E2E solo `systek_e2e`. No retirar la guardia de `tests/TestCase.php`.
- **Secretos:** no leer `.env` ni `backend/storage/app/private/**`; no registrar contraseñas, tokens, códigos MFA ni datos bancarios.
- Sin dependencias ni carpetas base nuevas sin aprobación. Seguir archivos hermanos.
- Cada entrega funcional actualiza `README.md` y, si corresponde, `docs/<n>-iteracion.md`.

## Política de agentes

La sesión principal (Sonnet) implementa. Los subagentes son la excepción. Procesos por tipo de pedido en las skills (`/feature`, `/bugfix`, `/database-change`, `/incident`, …).
- **SIMPLE** (una capa, patrón existente): sin subagentes. Implementar y verificar directamente.
- **MEDIUM**: 1 especialista Sonnet (+ `qa` si aplica).
- **COMPLEX**: `architect` (Opus) solo si hay una pregunta arquitectónica → especialista → `qa` → `final-reviewer`. Máximo 3 subagentes.
- **CRITICAL** (auth, MFA, roles, dinero/precios/aprobación, secretos, corrupción de datos): puede sumar `security-reviewer` y `final-reviewer` con `model: opus`.
- Siempre secuencial. Nunca Agent Teams salvo pedido explícito.
- Antes de invocar Opus, formula la pregunta concreta que debe resolver. Sin pregunta concreta, no hay Opus.
- Incidentes: empezar con Sonnet (`incident-investigator`); escalar a Opus solo con evidencia contradictoria, varios sistemas o riesgo crítico.
- Handoff: resumen compacto (objetivo, criterios, archivos, decisiones, cambios, pruebas pendientes). Nadie re-explora desde cero.
- Búsqueda: Grep/Glob → fragmento → archivo completo solo si hace falta. Ignorar `vendor`, `node_modules`, `.nuxt`, `.output`, `dist`, `coverage`, `storage/logs`, cachés. Para barridos amplios, `Explore`.
- Pruebas incrementales: archivo/filtro → módulo → suite completa solo si el riesgo lo justifica.

## Compactación

Conservar: requerimiento, criterios, decisiones, archivos modificados, errores abiertos y resultado de las pruebas. Descartar: exploraciones, logs, salidas de comandos e intentos descartados.

## Terminado

Criterios demostrados; Pint + PHPUnit verdes si cambió backend; typecheck + build si cambió frontend; E2E si cambió un flujo y el entorno existe; pruebas nuevas (incluidos permisos por rol); docs al día; reporte honesto de lo no verificado.
