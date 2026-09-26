# Diseño — Asistente IA (borrador desde texto libre)

Estado: **aprobado para implementar** (2026-09-26). Decisiones del usuario: función v1 = borrador desde texto libre; a Anthropic solo se envía el texto del cotizador y el catálogo reducido a SKU, descripción, familia y unidad (sin clientes, NIT, precios, costos, márgenes ni datos bancarios); proveedor Claude Haiku 4.5 (`claude-haiku-4-5-20251001`); desactivado por defecto; clave solo por variable de entorno privada.

## 1. Resumen

`POST /api/v1/quotes/assist` (roles `admin` y `quoter`; el aprobador no) ejecuta el caso de uso de solo lectura `ProposeQuoteDraft`: lee el catálogo vigente, llama a Claude con **una única herramienta** de esquema estricto, revalida todo en un validador de dominio puro y devuelve una propuesta **sin montos**. No guarda cotizaciones ni instantáneas ni crea tablas. El editor carga la propuesta, llama al `quotes/preview` existente para los totales y el humano guarda por `POST /quotes` como siempre.

## 2. Configuración (`config/ai_assistant.php`, claves también en `.env.example`)

`AI_ASSISTANT_ENABLED=false`, `ANTHROPIC_API_KEY=` (vacía), `AI_ASSISTANT_MODEL=claude-haiku-4-5-20251001`, timeout 12 s, conexión 3 s, `max_output_tokens` 2048, `max_catalog_items` 400, `max_description_chars` 120, `max_input_chars` 4000, límites 5/minuto y 30/día por usuario. La URL base y el prompt de sistema son **constantes en código**, no en env, para que la configuración no pueda redirigir los datos ni alterar el prompt.

## 3. Capas

- Ruta en el grupo `role:admin,quoter` con `throttle:quote-assist` y `Cache-Control: no-store`. `AssistQuoteRequest`: solo `text` (10–4000) y `family` opcional (`QuoteFamily`). Rechaza con 422, sin enviar nada, texto que parezca cuenta bancaria (`ClauseText::looksLikeBankAccount`) o correo.
- `QuoteAssistController` sin consultas; traduce `AssistantDisabled` → 503 `assistant_disabled` y `AssistantFailed` → 502 `assistant_unavailable` (mensaje genérico, sin detalles del proveedor).
- `Application/Quotes`: interfaz `QuoteDraftAssistantClient`, caso de uso `ProposeQuoteDraft`, excepciones. `Infrastructure/Anthropic/AnthropicQuoteDraftClient` con el cliente HTTP de Laravel (`x-api-key`, `anthropic-version: 2023-06-01`), sin dependencias nuevas.
- `Domain/Quotes/AssistantProposal`: validador/normalizador puro.
- `CatalogRepository::assistantCatalog(date, family, limit)`: reutiliza el filtro de precios vigentes pero **no selecciona precios, costos ni `valid_until`**; ordena por SKU, detecta truncado con `limit+1` y recorta descripciones.
- Sin migración.

## 4. Llamada al proveedor

`temperature 0`; una sola herramienta `propose_quote_draft` con `tool_choice` forzado. Esquema: `family` (enum o null), `lines` (máx. 30 de `{sku ^[A-Z0-9_-]+$ ≤60, quantity ^\d{1,5}(\.\d{1,3})?$}`), `scope` y `exclusions` (≤3000), `missing_information` (≤10 de ≤200), `additionalProperties:false`. Mensaje de usuario: `<catalogo>` (JSON compacto) y `<solicitud>`; se neutralizan etiquetas de cierre y se quitan caracteres de control. Un solo reintento a 300 ms solo ante error de conexión o 429/503/529. Cualquier fallo → `AssistantFailed` con razón interna, sin registrar cuerpo ni clave. Sin `tool_use` o `stop_reason=max_tokens` → `invalid_output`. Desactivado o sin clave → `AssistantDisabled` antes de cualquier llamada HTTP.

## 5. Validación de la salida (`AssistantProposal`)

Familia fuera de `QuoteFamily` → null + aviso `invalid_family`. SKU que no esté en el catálogo enviado → descartado (`unknown_sku`; cubre inventados, históricos e inactivos). Duplicados → `duplicate_sku`. Cantidad como **cadena** válida y > 0 (un número JSON o `1e3` se rechaza) → si no, `invalid_quantity`. Familia de la partida distinta → aviso `family_mismatch`. `scope`, `exclusions` y `missing_information`: sin caracteres de control, cortados, y si parecen cuenta bancaria → null + `sensitive_text_removed`. `description`, `unit` y `price_version_id` salen del servidor, nunca del modelo; `discount_bps` siempre 0.

## 6. Contrato

Solicitud `{text, family?}`. 200: `{data: {request_id, generated_by:"ai", model, family, scope, exclusions, lines:[{sku, price_version_id, description, unit, family, quantity:"8.000", discount_bps:0}], missing_information[], warnings:[{code, sku?}], catalog_truncated}}` — **sin campos monetarios**. Errores: 401/403 (rol o MFA), 422, 429, 503, 502.

## 7. Auditoría

`quote.assist_requested` (también en fallos; no si está desactivado) con `input_chars`, `family_hint`, `catalog_items_sent`, `catalog_truncated`, `model`, tokens, `latency_ms`, `outcome` (`ok|invalid_output|provider_error|timeout`), `lines_proposed`, `lines_discarded`. **Nunca** el texto del usuario ni la respuesta.

## 8. Proxy Nuxt

Agregar `quotes/assist` a la allowlist de POST; timeout de 35 s solo para esa ruta (hoy 15 s fijo); dejar pasar el 503 solo para esa ruta (hoy llegaría como 502). Se mantienen Origin y JSON.

## 9. Interfaz

Panel plegable en el editor: textarea (contador 4000), aviso «No incluyas nombres, NIT, teléfonos, correos ni datos bancarios; el texto se envía a Anthropic (Claude)», botón «Proponer borrador» deshabilitado mientras corre. Si el editor tiene contenido pide confirmación. Al cargar: fija familia, alcance, exclusiones y líneas, dispara el preview; cliente, sede, contacto y cláusulas quedan al humano. Etiqueta «Propuesto por IA» hasta que se edite, avisos, `missing_information` y banner de revisión obligatoria. Sin `v-html`. Con 503, mensaje de desactivado.

## 10. Pruebas (`Http::fake`, nunca el servicio real)

Desactivado (503 y sin envío), roles (aprobador 403, sin sesión 401), texto con dígitos o correo (422 sin envío), caso feliz (sin campos monetarios, sin cotizaciones creadas, auditoría sin texto), cuerpo enviado (modelo, `x-api-key`, `tool_choice`, catálogo con 4 claves; ausentes uuids, precios, costos, clientes, empresa, banco), descartes (SKU inexistente/histórico/inactivo, cantidades inválidas, duplicados, familia inválida, alcance con cuenta), fallos 502 genéricos (500, conexión, sin `tool_use`, `max_tokens`), un solo reintento ante 529, 429 por cuota, truncado y filtro por familia. Unitaria de `AssistantProposal`. E2E solo del camino desactivado.

## 11. Valores provisionales (decisiones abiertas)

5/min y 30/día por usuario; 400 ítems; cuota en caché de archivo (se reinicia con `cache:clear`; alternativa durable: contar filas de auditoría); sin `assist_request_id` en `POST /quotes` en v1; el aprobador no usa el asistente.

## 12. No verificable sin la clave real

Calidad y tasa de SKUs válidos del modelo con este esquema, tokens y costo reales, latencia (¿alcanzan 12 s?), formato real de errores y 529 de Anthropic. Un catálogo de 400 ítems ≈ 12–15 k tokens de entrada por llamada.
