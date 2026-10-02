# Iteración 16: PDF oficiales en Amazon S3

Los únicos archivos de cotización que se almacenan son los **PDF oficiales emitidos** (los borradores se generan al vuelo y no se guardan). Con `QUOTE_PDF_STORAGE=s3` se guardan en S3 en lugar de la base. Reemplaza la decisión D3 de [diseno-emision-oficial.md](diseno-emision-oficial.md) (PDF cifrado en la base) solo para las emisiones nuevas.

## Comportamiento

- `QUOTE_PDF_STORAGE` = `database` (por defecto: local, pruebas, E2E) o `s3`. Con `s3` la emisión sube el PDF al disco `official_pdfs` (bucket `AWS_BUCKET`, prefijo `official-quotes/`, clave `<emission_id>.pdf`) y `quote_emission_files` guarda solo `object_key` (`content` queda vacío).
- La subida ocurre dentro de la transacción de emisión: si S3 falla, se lanza excepción, se revierte todo y **no se emite** (500 genérico). Si la subida funciona pero la transacción revierte después, queda un objeto huérfano sin referencia (inofensivo; no hay `DeleteObject`, ver IAM).
- Descarga: se lee el objeto, se comprueba el `pdf_sha256` guardado en la emisión y solo entonces se entrega; si falta el objeto, S3 no responde o el hash no coincide: 500 genérico + auditoría `quote.emission_integrity_failed`. Nunca hay URLs prefirmadas ni públicas: el PDF solo sale por la API autenticada.
- **Cifrado:** ya no se cifra con `APP_KEY` (el número de cuenta del PDF es de conocimiento público, es como se recibe el pago). Protección: bucket privado, SSE-S3 (`AES256`) exigido en cada subida y verificación de SHA-256. Las demás reglas sobre datos bancarios siguen (no se registran; en la base solo el resumen enmascarado).
- **Emisiones anteriores:** los PDF ya archivados en la base se siguen sirviendo desde allí (decisión: solo los nuevos van a S3). No hay migración de los existentes.
- Migración `2026_09_27_000001`: agrega `object_key` (única) y hace `content` nulo; reversible, y `down` se niega si hay PDF que solo existen en S3.

## Configuración (paso manual)

1. Bucket S3 privado: *Block all public access*, cifrado por defecto SSE-S3 y, recomendado, versionado (o Object Lock) porque los PDF emitidos son inmutables.
2. Usuario IAM con solo `s3:PutObject` y `s3:GetObject` sobre `arn:aws:s3:::<bucket>/official-quotes/*`.
3. En el entorno privado del backend: `QUOTE_PDF_STORAGE=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`.
4. En desarrollo con datos reales: `scripts/backup-local-db.sh` y luego `php artisan migrate` (la migración no borra datos).

## Respaldo

Los PDF en S3 **no** entran en `backup-local-db.sh`: dependen del versionado/replicación del bucket. Los emitidos antes siguen en la base y sí entran en la copia.

## Pruebas

`QuoteEmissionTest`: emisión con S3 (objeto guardado, `content` vacío, descarga con hash correcto, objeto alterado o ausente → 500 + auditoría), PDF antiguos de la base servidos tras cambiar a S3, y fallo de S3 sin emisión ni filas. Verificado con `Storage::fake`; **no se probó contra un bucket real**.

## Base de conocimiento (Fase 1)

Migraciones `2026_09_28_000001/2` (aplicadas en desarrollo; reversibles, probadas up/down en `systek_test`), modelos, `KnowledgeRepository` (+ Eloquent y provider), casos de uso `ImportKnowledge` y `BackfillKnowledge`, comandos `systek:import-knowledge` y `systek:backfill-knowledge`. Decisiones: precio de referencia interno por línea (partidas compuestas), nunca enviado a Anthropic ni vigente; `source_ref` de Drive es un hash; auditoría solo con contadores (`knowledge.imported`, `knowledge.backfilled`); entradas con riesgo del depurador quedan en `needs_review`. `KnowledgeSeeder` (`db:seed --class=KnowledgeSeeder --force`, idempotente, datos en `database/seeders/data/knowledge_drive.psv`) para poblar las tablas en cualquier entorno. Pruebas: `tests/Feature/KnowledgeBaseTest.php`, `KnowledgeSeederTest.php`. Fuera de alcance: recuperación (F2), captura al aprobar (F3), líneas libres (F3b), curación (F4).

## Recuperación de precedentes (Fase 2)

`KnowledgeRepository::similar` (PostgreSQL: `ts_rank_cd` + `pg_trgm`, confianza, boost de familia 1.15, decaimiento a 0.85 en 24 meses; SQLite: respaldo por términos), `skuFrequencyForFamily` y `recordAssistRequest`. Una entrada por raíz, máximo `AI_KNOWLEDGE_MAX_AI_ASSISTED` de origen IA y Drive limitado (un Drive por cada 2 aprobadas; el primero siempre cabe para que la base inicial solo de Drive sirva). `ProposeQuoteDraft` (con `AI_KNOWLEDGE_ENABLED`) arma precedentes P1..Pn en lista cerrada: sin `reference_price_cents`, moneda ni cliente; los textos se vuelven a depurar (`KnowledgeScrubber`, `clean()`), se acotan a `MAX_PRECEDENT_CHARS` y el cliente descarta cualquier otro campo. La herramienta suma `precedent_ids` solo si hay precedentes; `AssistantProposal` descarta ids no enviados y devuelve `precedents_used [{source, captured_at}]`. `assistantCatalog` recibe SKU prioritarios (de los precedentes y los más frecuentes) que van primero. Un fallo de recuperación o de registro nunca rompe al asistente. Con la bandera apagada el comportamiento y la respuesta no cambian. `systek:eval-knowledge` mide hit@k sin llamar al proveedor (0 si no hay aprobadas). Pruebas: `tests/Feature/QuoteAssistKnowledgeTest.php` (SQLite y systek_test). Pendiente: hit@4 real y revisión manual de 20-30 propuestas antes de activar.


## Fase 3: captura continua

Al aprobar (`QuoteReviewController::review`, decisión `approve`) se registra, con `DB::afterCommit` y dentro de try/catch, `RecordQuoteKnowledge`: una entrada por cotización raíz construida desde la instantánea aprobada (`KnowledgeEntry::fromSnapshot`, descripciones leídas del catálogo), con `reference_price_cents` + `COP` por línea y nada más monetario. `risk=review` (o `AI_KNOWLEDGE_AUTO_ACTIVATE=false`) => `needs_review`; si no, `active`. La revisión aprobada posterior reemplaza (una anterior no pisa a una posterior); se conservan `issued` y la curación admin. Si la captura falla se audita `knowledge.capture_failed` (sin datos) y la aprobación queda intacta. Al emitir, `IssueQuote` invoca `MarkKnowledgeIssued` (trust 1.10) también tras el commit y tolerante a fallos.
`assist_request_id` opcional en POST `/quotes` y `/quotes/{id}/revisions`: se enlaza a la raíz solo si es del actor, no está enlazada y tiene 24 h o menos; si no, se ignora en silencio y nunca entra en la instantánea. `Domain/Quotes/AssistDiff` (puro) compara propuesta vs. aprobada (kept, qty_changed, removed, added); se guardan contadores y `approved_at` en `quote_assist_requests` y `ai_assisted`/`human_edit_ratio` en la entrada. Frontend: `pages/index.vue` recuerda `request_id` y lo envía al guardar. Pruebas: `tests/Feature/KnowledgeCaptureTest.php`.

## Fase 4 (backend): curación y métricas

Rutas solo administrador (`role:admin`, MFA, `Cache-Control: no-store`; PATCH con `throttle:30,1`): `GET /ai-knowledge`, `GET /ai-knowledge/metrics`, `GET /ai-knowledge/{id}`, `PATCH /ai-knowledge/{id}` (`status` active|excluded y/o `requirement_text`/`scope`/`exclusions`, con `reason` obligatorio). Caso de uso `ReviewKnowledgeEntry` (transacción con bloqueo de la entrada); los textos editados pasan de nuevo por `KnowledgeScrubber`. Auditoría `knowledge.status_changed` y `knowledge.edited` solo con id, estados, nombres de campo y motivo. El detalle muestra `reference_price` como cadena decimal (nunca costos ni datos de cliente). Las entradas revisadas por un admin conservan su estado ante reimportaciones. Aceptación = líneas conservadas / (conservadas + con cantidad cambiada + eliminadas) de propuestas aprobadas. Pruebas: `tests/Feature/AiKnowledgeAdminTest.php`. Pendiente: página admin y línea «Basada en…» (frontend).

## Fase 4 (frontend): curación

Página solo administrador `frontend/app/pages/conocimiento-ia.vue` (menú «Conocimiento IA» y guardia de ruta en `app.vue` y `middleware/auth.global.ts`): tarjetas de métricas, filtros (estado, origen, familia, texto), lista paginada, detalle con líneas y precio de referencia (cadena formateada con `money`, sin cálculo), edición de texto y excluir/activar, ambos con motivo obligatorio (3 a 500). Composable `useAiKnowledge.ts`, tipos en `shared/knowledge.ts`. En `QuoteAssistPanel.vue` se muestra «Basada en N cotizaciones aprobadas» solo si la respuesta trae `precedents_used` (sin números de cotización). Pendiente: prueba Playwright de admin (listar/excluir) requiere el entorno E2E con datos de conocimiento.

## Líneas libres: tramo 1 (pasos 1-3 de `docs/diseno-lineas-libres.md`)

Implementado: tabla `quote_free_line_items` (vínculo, reversible) con `QuoteRepository::linkFreeLine/freeLineLinks`; `CatalogRepository::activeExactMatch`; `Domain/Catalog/FreeLineSku`; rama `free` de `QuotePricer` (valores de la propia línea, familia de la cotización, máx. 20); reglas de línea libre en `PreviewQuoteRequest`; `CreateQuote` genera `free_line_id` y rechaza duplicados exactos (422 con SKU); `SnapshotCompatibility` añade `line_type='catalog'` solo al leer; `ApprovalValidation` con modos `approval` (dos avisos) y `emission` (línea libre resuelta por vínculo y precio vigente igual al de la instantánea; sin vínculo 422), usado por `IssueQuote`.
## Líneas libres: tramo 2 (pasos 4-6)

Implementado: `Application/Quotes/ReviewQuote` + `ReviewQuoteRequest` (el controlador solo coordina). Al aprobar con líneas libres exige `confirm_new_items` exacto (422 «Confirma los ítems que se crearán»); en la misma transacción crea ítem activo (`FreeLineSku`), precio v1 (vigencia `config/catalog.php`, 90 días), vínculo y auditoría `catalog.created_from_quote`/`price.published` sin costos. Respuesta añade `created_items:[{free_line_id,catalog_item_id,sku,price_version_id}]`. `GET /catalog/similar` (pg_trgm+unaccent; LIKE en SQLite). Conocimiento resuelve la línea libre por el vínculo (`freeLineCatalog`). `show` añade `linked_item` y el cotizador ve `cost_cents` solo de sus líneas libres sin vincular. Pruebas: `FreeLineApprovalTest`.
Pendiente: prueba HTTP completa hasta emitir y E2E del flujo en navegador.
## Líneas libres: tramo 3 (frontend, paso 7)

Implementado: `shared/types.ts` (`LineInput` = catálogo | `FreeLineInput`, `SimilarItem`, `CreatedItem`, `PricedLine` con `line_type`/`free_line_id`/`linked_item`); composable `useCatalogSimilar` (debounce 400 ms, mínimo 3 caracteres, `GET /api/backend/catalog/similar`, ya en la allowlist); editor (`pages/index.vue`): botón «Línea libre» (máx. 20) con descripción, unidad, cantidad, precio, costo, IVA y descuento, sugerencias «Usar este ítem» (la línea pasa a catálogo) y aviso «Se creará un ítem nuevo en el catálogo al aprobar». El precio nunca se autocompleta. Al revisar: libres sin vincular se editan como libres (costo desde `cost_cents` con `centsToDecimal`, sin flotantes); vinculadas pasan a catálogo con el `price_version_id` de `linked_item`. Detalle (`borradores/[id].vue`): tarjeta «Ítems que se crearán» con casilla obligatoria que habilita Aprobar y envía `confirm_new_items`; muestra los SKU de `created_items`. Sin cambios en el proxy. Verificado: typecheck y build; sin E2E de navegador.
