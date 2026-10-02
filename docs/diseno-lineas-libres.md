# Diseño — Líneas libres e ítem automático (Fase 3b)

Estado (2026-10-02): **diseñada, no implementada**. Proceso: `/feature`, CRITICAL (precios, aprobación, catálogo). Diseño del arquitecto; pendiente de confirmar las decisiones D1–D6 (§9).
Relacionado: `docs/diseno-base-conocimiento-ia.md` (Fase 3b), `.claude/harness/invariantes.md`.

## 1. Objetivo y decisiones ya tomadas

Las partidas de Systek son compuestas («cámara bala + DVR + mano de obra», un solo precio). Quien cotiza debe poder escribirlas como **línea libre** sin crear antes el ítem. Al aprobarse la cotización, se crea el ítem de catálogo para que el asistente pueda proponerlo luego.

Decisiones del usuario:

1. El ítem nace **activo** con **precio vigente al aprobar** (el aprobador, distinto del autor, valida el precio).
2. Anti-duplicados: al guardar una línea libre se **sugieren ítems parecidos** y el cotizador elige uno existente o confirma que es nuevo.
3. Una partida compuesta = **un ítem** (no se descompone). El precio es el cotizado.

## 2. Hallazgos del código que condicionan el diseño

- `price_versions.cost_cents` es unsigned NOT NULL y `publish` exige costo. `ApprovalValidation` compara margen mínimo por familia (aviso, no bloqueo). **La línea libre exige un costo ≥ 0 al guardar**; debe estar en la instantánea porque la aprobación compara `totals.cost` y el aprobador no puede añadirlo sin reescribirla.
- `catalog_items.description` es varchar(255); `unit` está restringida a unidad/metro/hora/servicio/licencia; el SKU cumple `^[A-Z0-9_-]+$` (60) y es único.
- El PDF solo usa description/unit/quantity/price_cents/tax_bps/discount_bps/amounts: funciona sin cambios.
- `ClauseCoherence` avisa si la familia de la línea no coincide con la de la cotización: la línea libre hereda la familia de la cotización.

## 3. Línea libre: payload e instantánea

Cada elemento de `lines` en `POST quotes`, `quotes/preview` y `quotes/{id}/revisions` es:

- **Catálogo (igual que hoy):** `{price_version_id, quantity, discount_bps}`.
- **Libre:** `{type:'free', description (5..255, sin caracteres de control), unit (enum del catálogo), quantity, price (cadena decimal > 0), cost (cadena decimal ≥ 0), tax_bps (0|500|1900), discount_bps, confirmed_new: accepted}`.

Conversión a centavos con `DecimalMoney::cents` (máx. 1e9 centavos); máximo 20 líneas libres por cotización. El servidor genera `free_line_id` (uuid) en `CreateQuote`; el cliente nunca lo envía.

Instantánea: `line_type:'free'`, `free_line_id`, `catalog_item_id:null`, `price_version_id:null`, `is_demo:false`, `family` = la de la cotización, más sus propios `price_cents`, `cost_cents`, `tax_bps`, `discount_bps`. **La instantánea nunca se reescribe.** `SnapshotCompatibility` normaliza `line_type ??= 'catalog'` solo al leer. `QuoteCalculator` no cambia (recibe enteros).

Errores 422: `lines.N.description` «Ya existe el ítem SKU X activo con esa descripción; selecciónalo.» (coincidencia exacta normalizada con ítem activo de la misma familia), unidad o rango inválidos, falta `confirmed_new`.

## 4. Cálculo, aprobación y emisión

- `QuotePricer`: rama `free` que revalida rangos y ausencia de ids y toma los valores de la propia línea.
- **Modo `approval`** (`ApprovalValidation`): valores de la instantánea; recálculo determinista; se comparan totales, ReteIVA, reglas y vigencia. Dos avisos: «línea libre: al aprobar se crea ítem activo con precio vigente» y «posible duplicado de SKU X» si apareció una coincidencia exacta entre el guardado y la aprobación (aviso; decide el aprobador).
- **Modo `emission`** (`IssueQuote`): cada línea libre se resuelve por el vínculo (§5) y pasa por `lockedPrice(price_version_id)`: ítem activo y precio aprobado y vigente, con `price_cents`/`cost_cents`/`tax_bps` iguales a la instantánea. Si un admin publicó otro precio después, la emisión se bloquea («crea una revisión»), igual que con el catálogo. Sin vínculo → 422.

## 5. Vínculo instantánea ↔ ítem (sin reescribir la instantánea)

Tabla nueva `quote_free_line_items` (migración `2026_10_02_000001_create_quote_free_line_items`, reversible con `dropIfExists`): `id` uuid, `quote_id` FK quotes restrict, `free_line_id` uuid, `catalog_item_id` FK, `price_version_id` FK, `approved_by` FK users, `created_at`. Únicos `(quote_id, free_line_id)` y `catalog_item_id`. No modifica `catalog_items` ni `price_versions`.

## 6. Revisión (aprobación) — `Application/Quotes/ReviewQuote`

`QuoteReviewController::review` queda como coordinación y delega en `ReviewQuote` + `ReviewQuoteRequest`. `POST quotes/{id}/review` añade `confirm_new_items: list<uuid>` (lista exacta de `free_line_id`); obligatorio al aprobar si hay líneas libres (si falta o no coincide → 422 «Confirma los ítems que se crearán»). Respuesta: la de hoy más `created_items: [{free_line_id, catalog_item_id, sku, price_version_id}]`. Con *return* no se crea nada.

Orden en la transacción:

1. `findForReview` (bloquea la cotización).
2. Estado e independencia del autor (403).
3. `check(approval)`: cliente → ítem→precio compartidos → regla.
4. `transition`.
5. Por cada línea libre: `FreeLineSku::for(family, free_line_id)` (prefijo de familia + `-L` + 8 hex; reintenta con más hex si existe; el índice único es la protección final) → `createItem(active:true, is_demo:false)` → `publishPrice(item, {price_cents, cost_cents, tax_bps, valid_from: hoy, valid_until: hoy + config('catalog.free_line_price_days', 90)})` (versión 1; respeta «publicar crea versión nueva») → `linkFreeLine` → auditoría `catalog.created_from_quote` y `price.published` con `{origin:'quote_approval', quote_id, free_line_id, version}` (sin costos).
6. Commit.
7. `afterCommit`: `RecordQuoteKnowledge` (tolerante a fallos).

**Si falla la creación del ítem, la cotización no se aprueba** (misma transacción). Idempotencia: la transición `in_review→approved` ocurre una vez y el índice único protege el vínculo.

## 7. Visibilidad, sugerencias y conocimiento

- `QuoteVisibility`: el `quoter` sigue sin ver `totals.cost`, `profit` ni `amounts.cost`. Recomendado (D3): mostrarle `cost_cents` solo en sus líneas libres no vinculadas (es su propio dato; si no, al editar una devuelta pierde el costo). Los ítems creados quedan con costo de catálogo, oculto como cualquier otro. El PDF no cambia.
- `GET /api/v1/catalog/similar?q=&family=` (roles admin/quoter/approver, `throttle:60,1,catalog-similar`, `q` 3..120, hasta 8 resultados): `{id, sku, description, unit, family, price_version_id, price (cadena), valid_until, score}`; solo ítems activos con precio vigente, sin costos. PostgreSQL `pg_trgm` + `unaccent`; SQLite con LIKE por términos. Si el cotizador elige una sugerencia, la línea pasa a ser de catálogo. Allowlist del proxy: solo `GET catalog/similar`.
- Revisión de una cotización aprobada: `show` incluye `linked_item`; el editor convierte esas líneas en líneas de catálogo con el `price_version_id` vinculado. La instantánea vieja no cambia.
- Conocimiento: `RecordQuoteKnowledge`, `BackfillKnowledge` y `EvaluateKnowledge` resuelven el ítem de las líneas libres por el vínculo, así la entrada usa el SKU creado.

## 8. Invariantes y riesgos

| Invariante | Protección |
| --- | --- |
| Dinero en centavos | Cadenas → `DecimalMoney` → enteros |
| Publicar crea versión nueva | Se usa `publishPrice` |
| No reescribir instantáneas | Tabla de vínculo |
| Bloqueos ítem→precio | Dentro de `publishPrice` y `lockedPrice` |
| Aprobación recalcula | Modo `approval`; emisión estricta |
| Costos por rol | `QuoteVisibility`; PDF intacto |
| Auditoría | `catalog.created_from_quote`, `price.published` con origen |
| Migraciones | Una tabla nueva, reversible |
| Autorización | Rol en la ruta + autor ≠ aprobador |

Riesgos:

- **Atajo para publicar precios:** mitigado: solo se crean ítems nuevos (nunca se cambia el precio de uno existente), con aprobación independiente, confirmación explícita por línea, auditoría con origen y máximo 20 por cotización. Queda el riesgo de dos personas coludidas.
- **Blanqueo de precios importados:** el código no distingue un precio tecleado de uno copiado de Drive o de la base de conocimiento. Protección: el asistente no devuelve precios ni líneas libres; el frontend nunca rellena el precio desde `reference_price_cents`; el aprobador valida. Es una limitación declarada, no una violación.
- **Duplicados:** la sugerencia es un consejo; un admin depura el catálogo (desactivar).
- **Descripción > 255:** v1 la rechaza (pasar a `text` requiere migración con `down` que podría truncar).
- **Emisión tras republicar:** si el admin vuelve a publicar el precio después de aprobar, la emisión se bloquea como con cualquier ítem de catálogo.

Alternativas descartadas: crear el ítem en `afterCommit` (deja aprobada una cotización sin ítem); crear el ítem inactivo (el usuario decidió activo); reescribir la instantánea con `catalog_item_id` (viola la inmutabilidad); apuntar la línea libre a un ítem existente o inactivo de Drive (atajo para cambiar o reactivar precios); que el aprobador ingrese el costo (rompe la comparación de totales).

## 9. Decisiones pendientes

| # | Pregunta | Propuesta |
| --- | --- | --- |
| D1 | Prefijo del SKU | Prefijo de familia + `-L` + hex |
| D2 | Límite de descripción | 255 caracteres |
| D3 | ¿El cotizador ve el costo de sus líneas libres? | Sí |
| D4 | Vigencia del precio creado | 90 días por defecto |
| D5 | Tasas de IVA permitidas | 0 / 5 % / 19 % |
| D6 | ¿Las sugerencias incluyen ítems inactivos de Drive? | Solo informativos, no seleccionables |

## 10. Plan de implementación (un especialista; luego `qa` → `security-reviewer` y `final-reviewer` con Opus)

1. Migración y modelo `quote_free_line_items`; `QuoteRepository` (`linkFreeLine`, `freeLineLinks`); pruebas up/down en `systek_test`.
2. `SnapshotCompatibility`, rama `free` de `QuotePricer`, `Domain/Catalog/FreeLineSku`; reglas en `PreviewQuoteRequest`/`CalculateQuoteRequest`; `CreateQuote` con `free_line_id` y dedupe (`CatalogRepository::activeExactMatch`).
3. `ApprovalValidation` con modos y avisos; `IssueQuote` en modo emisión.
4. `ReviewQuote` + `ReviewQuoteRequest`; creación de ítems, vínculo y auditoría; `RepositoryArchitectureTest` en verde.
5. `similarItems` + `CatalogController::similar` + ruta + throttle + allowlist del proxy.
6. Conocimiento: resolver líneas libres por el vínculo.
7. Frontend: tipos (`LineInput` unión con `FreeLineInput`), editor de línea libre con sugerencias, tarjeta «Ítems que se crearán» con casilla en la pantalla del aprobador, mapeo de revisiones, composable `useCatalogSimilar`.
8. README y nota de iteración.

Pruebas mínimas: roles (quoter guarda; approver/admin no guardan; quoter no aprueba; el autor no aprueba la suya); aprobar con `confirm_new_items` correcto crea ítem activo + versión 1 vigente con los centavos exactos + vínculo + auditoría; sin confirmación → 422 y nada creado; *return* no crea; atomicidad (fallo en `createItem`/`publishPrice` → sigue `in_review`); instantánea byte-idéntica antes/después; instantánea antigua sin `line_type` aprueba y emite igual; emisión con v2 publicada → 422; dinero con 3 decimales o negativo → 422; el PDF de borrador no muestra costos; `catalog/similar` sin inactivos ni costos; proxy solo GET; E2E guardar → enviar → aprobar con otro usuario → el ítem aparece en `GET catalog`.
