# Iteración 12: emisión oficial de cotizaciones aprobadas

Diseño: [diseno-emision-oficial.md](diseno-emision-oficial.md). **No incluye envío** al cliente.

## Qué incluye

- **Estado `issued`** (final), solo desde `approved`. La aprobación interna previa siempre es obligatoria y quien aprueba nunca es el autor.
- **Autorización de emisión configurable** (`emission_requires_authorization`, por defecto activada): la cambia solo el administrador al publicar una versión de empresa (con motivo, auditado). Activada: emiten administrador o aprobador distinto del autor. Desactivada: emiten el administrador o el cotizador dueño. Cada emisión congela el valor usado.
- **Revalidación estricta** al emitir (precios, cláusulas, ReteIVA, totales, vigencia en hora de Bogotá, empresa completa). Si algo cambió tras aprobar, responde 422 y hay que crear una nueva revisión. El rol se comprueba antes de mostrar detalles de validación.
- **PDF oficial** generado una sola vez, archivado cifrado en `quote_emission_files` con SHA-256 verificado en cada descarga; nunca se regenera. La cuenta bancaria completa solo vive dentro de ese PDF; la emisión guarda un resumen enmascarado.
- **Revisiones posteriores**: la emisión de una versión anterior queda intacta; al emitir la nueva se marca la anterior como reemplazada. El PDF de borrador de una emitida responde 409.
- **Bloqueos**: cotización raíz → cotización → cliente → ítem → precio → regla → cláusula → versión de cláusula → empresa (compartido).

## API

| Método | Ruta | Acción |
| --- | --- | --- |
| POST | `/quotes/{id}/issue` | Emitir (`reason` obligatorio; límite 10/min) |
| GET | `/quotes/{id}/official-pdf` | Descargar el PDF oficial archivado |

`GET /quotes/{id}` agrega `emission`, `can_issue` e `issue_blockers`. Ambas rutas están en la allowlist del proxy. Frontend: botón y confirmación en el detalle, interruptor en `/empresa`.

## Verificación

Pint aprobado; PHPUnit 207 aprobadas y 2 omitidas en SQLite en memoria, 209 en PostgreSQL `systek_test`; typecheck y build de Nuxt aprobados. Auditoría de seguridad (Opus, lectura de código) sin hallazgos bloqueantes; se corrigió el orden del chequeo de rol y se alineó el diseño.

## Pendiente / no verificado

- **Migración de desarrollo**: no aplicada; requiere `scripts/backup-local-db.sh` antes.
- **E2E**: `emission.spec.ts` no se ejecutó y no hay E2E del flujo completo (exige un segundo usuario). Sin revisión visual en 1440 y 390.
- Con la autorización activada, el aprobador que aprobó también puede emitir (solo se exige ≠ autor); exigir ≠ aprobador es una decisión abierta.
- Diferencia menor de zona horaria: `can_issue` usa Bogotá y `ApprovalValidation` usa `now()`; falla del lado seguro.
- Inmutabilidad a nivel de aplicación (sin triggers en BD). Respaldar `APP_KEY`: sin ella los PDF archivados son ilegibles.
- Sin verificar: `zend.exception_ignore_args`, bloqueos reales bajo concurrencia en PostgreSQL, `QuoteVisibility` sobre los campos nuevos.
