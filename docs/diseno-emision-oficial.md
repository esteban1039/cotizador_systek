# Diseño — Emisión oficial de cotizaciones aprobadas

Estado: **aprobado para implementar** (2026-09-25) con los valores provisionales de §12 y el ajuste D1 (autorización configurable, §1.1). Base: [diseno-iteracion-11.md](diseno-iteracion-11.md). **No incluye envío** (correo, WhatsApp, enlaces públicos). `diseno-iteracion-12.md` corresponde a una iteración descartada (Drive).
No verificado al redactar: reglas de estado en `QuoteController::revise`, `zend.exception_ignore_args` del contenedor, vista `quotes.draft-pdf`, restricción CHECK en `quotes.status`, tamaño real de los PDF.

## 1. Decisiones

| Tema | Decisión | Motivo |
| --- | --- | --- |
| Transición | `approved → issued` (estado final). Sin anulación ni devolución en esta iteración. | Un documento emitido es inmutable; las correcciones se hacen con una nueva revisión. |
| Quién emite | Ruta `role:admin,approver,quoter`; la política decide (§1.1): con autorización activada emiten admin y aprobador distintos del autor; desactivada, admin y cotizador dueño. Defensa adicional: la última revisión `approve` debe tener `user_id ≠ created_by`. | "Nadie aprueba su propia cotización" ya se cumplió al aprobar; emitir es un acto administrativo del dueño. |
| Revalidación | Repite `ApprovalValidation::check(strict)` bajo bloqueo; exige empresa completa (nombre legal, NIT, firmante con cargo, cuenta), `quote_number` no nulo, revisión más reciente y `valid_until ≥ hoy` (hora de Bogotá). | La aprobación puede haber quedado vieja: precios republicados, cláusulas nuevas, ReteIVA o vencimiento. |
| Precio o vigencia vencidos | Bloquea con 422 y exige nueva revisión (D4). | Nunca se emite un precio histórico; las instantáneas no se reescriben. |
| Archivo | PDF en BD, cifrado (`encrypted`, base64), tabla `quote_emission_files` separada de los listados. | Alta atómica con la transacción, entra en `backup-local-db.sh`; el disco `local` tiene `serve => true` y quedaría fuera de la copia. |
| Integridad | `pdf_sha256` de los bytes en claro; se comprueba en cada descarga. Si no coincide: 500 genérico + auditoría `quote.emission_integrity_failed`, sin entregar el archivo. | Demuestra que el archivo servido es el emitido. |
| Reproducibilidad | Nunca se regenera; se sirve el archivo guardado. Se congelan instantánea (hash), versión de empresa, versiones de cláusula, `template_version` y versión de dompdf. Fecha de creación del PDF = `issued_at`. | dompdf no produce bytes idénticos; lo canónico es el archivo guardado. |
| Datos bancarios | Cuenta completa solo dentro del PDF oficial (D2). Se descifra en memoria dentro de `IssueQuote`; parámetros con `#[\SensitiveParameter]`; nunca en instantáneas, JSON, auditoría ni logs. La emisión guarda solo `bank_summary` enmascarado. | El cliente necesita la cuenta para pagar; el resto del sistema la sigue tratando como secreta. |
| Revisiones posteriores | Se permite revisar una emitida (V2 en borrador). V1 queda `issued` e intacta. Emitir V2 marca la emisión de V1 como reemplazada. Solo se emite la revisión más reciente (D6). | Una emisión vigente por linaje; el historial se conserva. |
| PDF de borrador | `GET /quotes/{id}/pdf` de una emitida → 409. | Evita dos documentos distintos con el mismo número. |

### 1.1 Autorización de emisión configurable (D1, decisión del usuario)

La aprobación interna previa **siempre** es obligatoria. Lo configurable es un segundo paso al emitir:

- Ajuste `emission_requires_authorization` (boolean, por defecto **true**), guardado como columna nueva de `company_versions` (migración propia, reversible). Lo cambia solo el administrador al publicar una versión de empresa (auditado, con motivo), y la emisión congela el valor usado.
- **true:** emiten solo `admin` y `approver`, y quien emite debe ser distinto del autor de la cotización. El cotizador no emite.
- **false:** emiten `admin` y el cotizador dueño (puede ser el autor).
- En ambos casos: la revisión `approve` debe ser de alguien distinto del autor, y se aplica toda la revalidación de §1.
- `can_issue` e `issue_blockers` reflejan la configuración vigente; el rol y la regla se comprueban en servidor.

## 2. Esquema (migración `2026_09_25_000001_create_quote_emissions`, reversible)

```
quote_emissions
  id uuid PK · quote_id uuid FK quotes RESTRICT UNIQUE · root_quote_id uuid FK RESTRICT INDEX
  quote_number string(20) · revision_number unsigned int
  approval_review_id FK quote_reviews RESTRICT   (tipo de PK real: por verificar)
  company_version_id uuid FK company_versions RESTRICT
  issuer json          (razón social, NIT, dirección, teléfono, correo, web, firmante y cargo; sin banco)
  bank_summary json    ({bank_name, account_type, last4})
  clause_version_ids json
  snapshot_sha256 char(64) · pdf_sha256 char(64) · pdf_size unsigned int
  filename string(80)  (COT-2026-0001-V1.pdf)
  template_version string(40) · renderer_version string(40)
  reason text · issued_by FK users RESTRICT · issued_at timestampTz
  superseded_at timestampTz NULL · superseded_by uuid FK quote_emissions NULL · timestamps
quote_emission_files
  emission_id uuid PK FK quote_emissions RESTRICT
  content longText     (Crypt(base64(pdf)); solo se selecciona en la descarga)
```

`down()`: `dropIfExists('quote_emission_files')` y `dropIfExists('quote_emissions')`. `quotes` no cambia (`status` es `string`); si existiera un CHECK, agregar `issued` en la misma migración. No toca datos existentes. Respaldo previo: `scripts/backup-local-db.sh`. Las únicas columnas mutables son `superseded_*`.

## 3. Orden de bloqueos (transacción de `IssueQuote`)

**cotización raíz → cotización → cliente → ítem → precio → regla → cláusula → versión de cláusula → empresa (compartido)**

- Raíz `FOR UPDATE` (`lockRevisionRoot`): serializa con revisiones y con otras emisiones del linaje.
- Empresa: nuevo `CompanyRepository::lockedCurrentForEmission()` con bloqueo compartido; `PublishCompanyProfile` espera, así la versión congelada es la vigente al confirmar.
- El render de dompdf ocurre bajo bloqueo (aceptable por el volumen interno). Actualizar `invariantes.md` y `arquitectura.md`.

## 4. Capas

- Ruta → `IssueQuoteRequest` (`reason` obligatorio, 5–2000) → `QuoteEmissionController@issue` → `Application\Quotes\IssueQuote` → `Domain\Quotes\EmissionPolicy` (reglas puras) + `ApprovalValidation` → contratos.
- `Application\Quotes\QuotePdfRenderer` (extraído de `GenerateQuotePdf`, sin cambiar la salida del borrador). Vista nueva `quotes.official-pdf`: número, versión, fecha de emisión, firmante y cargo, bloque de pago, id de verificación. Sin costos ni márgenes.
- `Application\Quotes\DownloadOfficialPdf`: `findVisible` → emisión → descifra → verifica hash → auditoría → bytes.
- Contrato `QuoteEmissionRepository` (`create`, `forQuote`, `activeForRoot`, `markSuperseded`, `fileContent`) + implementación Eloquent, ligada en `AppServiceProvider`. `QuoteRepository` recibe `latestRevisionNumber(rootId)` y `approvingReview(id)`.
- Ampliar `RepositoryArchitectureTest` al controlador y caso de uso nuevos.

## 5. Contrato de API (`/api/v1`)

- **POST `/quotes/{id}/issue`** — `role:admin,approver,quoter` (la política de §1.1 decide; 403 si no puede), `throttle:10,1,quote-issue`. Cuerpo `{"reason": "…"}`. 201 → `{data: {status: "issued", emission: {id, quote_number, revision_number, version_label, issued_at, issued_by, filename, pdf_sha256, snapshot_sha256, company_version, superseded_at}}}`. Errores: 404 no visible · 409 no está `approved`, ya emitida o hay revisión posterior · 422 aprobación inválida, empresa incompleta (`missing`), sin número o aprobación del propio autor · 429.
- **GET `/quotes/{id}/official-pdf`** — rol autenticado con visibilidad del registro. `application/pdf`, `attachment`, `Cache-Control: private, no-store`, `nosniff`. 404 sin emisión. Audita `quote.official_pdf_downloaded`.
- **GET `/quotes/{id}`** agrega `emission`, `can_issue`, `issue_blockers[]`; `emission_allowed = can_issue`. Sin banco ni resumen enmascarado para ningún rol.
- **GET `/quotes/{id}/pdf`** → 409 si `issued`. **GET `/quotes`** admite `status=issued`.
- Auditoría: `quote.issued`, `quote.emission_superseded`, `quote.official_pdf_downloaded`, `quote.emission_integrity_failed`; nunca datos bancarios.

## 6. Proxy Nuxt (`frontend/server/api/backend/[...path].ts`)

- POST: agregar `quotes/${uuid}/issue`.
- GET: agregar `quotes/${uuid}/official-pdf`; rama binaria con regex exacta `^quotes/${uuid}/(pdf|official-pdf)$` y nombre con patrón cerrado `COT-[0-9]{4}-[0-9]{4,6}-V[0-9]+\.pdf`.
- Se mantienen `Origin`, `application/json` y el saneamiento de errores.

## 7. Frontend

Detalle de cotización: botón "Emitir oficialmente" si `can_issue`, con confirmación irreversible (número, versión, total a pagar y motivo); `issue_blockers` visibles; insignia "Emitida", fecha, quién emitió, hash abreviado, "Descargar PDF oficial" y, si aplica, "Reemplazada por Vn". Se oculta la descarga del borrador. El frontend no calcula nada.

## 8. Invariantes en riesgo

| Invariante | Protección |
| --- | --- |
| Sin emisión sin aprobación humana | Solo desde `approved`; revalidación estricta; aprobador ≠ autor comprobado de nuevo. |
| Instantáneas inmutables | `IssueQuote` solo lee `snapshot`; `snapshot_sha256`; prueba de bytes antes/después. |
| Precios históricos nunca vigentes | `ApprovalValidation` bajo bloqueo ítem → precio. |
| Datos bancarios | Descifrado solo en el caso de uso; `#[\SensitiveParameter]`; PDF cifrado en reposo; pruebas de fuga con cuenta ficticia. Verificar `zend.exception_ignore_args=On`. |
| Autorización por registro | `findVisible` al emitir y descargar. |
| Dinero | El PDF usa los totales en cadena de la instantánea. |

## 9. Plan

1. `backend-laravel`: migración, modelo, contrato y repositorio, `lockedCurrentForEmission`, `EmissionPolicy`, `QuotePdfRenderer`, `IssueQuote`, `DownloadOfficialPdf`, controlador, rutas, 409 del borrador, campos de `show`, auditoría, `AuthNoStore`, invariantes y arquitectura.
2. `frontend-nuxt`: proxy, tipos, vista de detalle, descarga.
3. `qa`: pruebas §10, PostgreSQL `systek_test`, E2E si el entorno existe.
4. README, `docs/<n>-iteracion.md` y revisión de seguridad (Opus).

## 10. Pruebas mínimas

- Emite: cotizador autor de una aprobada por otro → 201, `issued`, archivo, `pdf_sha256` igual al de la descarga, auditoría sin banco.
- Roles: según §1.1 con el ajuste activado y desactivado; cotizador ajeno 404; el rol se comprueba antes que los detalles de validación; sin autenticar 401; sin MFA bloqueado.
- 409: borrador, en revisión, ya emitida, revisión posterior; doble emisión seguida.
- 422: vencida; precio republicado; cláusula nueva; cambio de ReteIVA; empresa sin cuenta o sin firmante; aprobación con `user_id = created_by`.
- Inmutabilidad: publicar precio, empresa o cláusula después no cambia los bytes ni `quotes.snapshot`.
- Integridad: alterar `content` → 500, auditoría y sin bytes.
- Revisiones: V2 se crea; emitir V2 marca V1 reemplazada; V1 sigue idéntica.
- Borrador de emitida → 409. `show` correcto por rol.
- Fuga: cuenta ficticia ausente de `audit_logs`, JSON, `quote_emissions` y logs.
- Migración: rollback y re-migración en `:memory:`. PostgreSQL: dos emisiones concurrentes → exactamente una.

## 11. Riesgos y alternativas descartadas

Riesgos: perder `APP_KEY` deja ilegibles los PDF archivados (D3; respaldar la clave); crecimiento de la BD; bloqueos durante el render; trazas con argumentos si `zend.exception_ignore_args=Off`.
Descartadas: disco privado (no atómico, fuera de la copia); regenerar en cada descarga (no reproducible y expone el banco); cuenta completa en instantánea o `quote_emissions`; emitir con precio histórico; anulación (D5); envío.

## 12. Decisiones del usuario (valor provisional)

- D1 Resuelta: autorización de emisión configurable (§1.1), por defecto activada.
- D2 Cuenta completa en el PDF oficial: sí.
- D3 PDF cifrado en reposo con `APP_KEY`: sí.
- D4 Precio que pasó a histórico tras aprobar bloquea la emisión: sí.
- D5 Sin anulación de emisiones en esta iteración: sí.
- D6 Bloquear la emisión si existe una revisión posterior: sí.
- D7 Firma solo como nombre y cargo en texto, sin imagen: sí.
