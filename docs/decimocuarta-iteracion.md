# Iteración 14: asistente IA (borrador desde texto libre)

Diseño: [diseno-asistente-ia.md](diseno-asistente-ia.md). **Desactivado por defecto.** La IA propone; el sistema valida y calcula; el humano revisa y guarda. Nada se envía a clientes.

## Qué hace

`POST /quotes/assist` (administrador y cotizador; el aprobador no) recibe un texto libre y devuelve una **propuesta sin montos**: familia, partidas del catálogo vigente (SKU y cantidad) y borrador de alcance y exclusiones. No guarda cotizaciones. El editor carga la propuesta, los totales salen del `quotes/preview` existente y el usuario guarda por el flujo normal. Cada campo y partida propuestos muestran «Propuesto por IA» hasta que se editan.

## Datos que salen hacia Anthropic

Solo el texto del cotizador y el catálogo reducido a SKU, descripción, familia y unidad. Nunca precios, costos, márgenes, clientes, NIT, datos de la empresa, datos bancarios ni identificadores internos. Antes de enviar se rechaza (422) texto con 8 o más dígitos (incluidos dígitos Unicode y separadores) o con correos; las descripciones del catálogo que contengan cuentas, correos o montos se omiten.

## Controles

- Salida forzada con una sola herramienta de esquema estricto y revalidada en el servidor: el SKU debe existir en el catálogo vigente enviado, la cantidad es una cadena decimal válida > 0, la familia debe ser válida, descripción, unidad y `price_version_id` los pone el servidor y el descuento es siempre 0. Texto con apariencia de cuenta bancaria o con caracteres invisibles/bidi se limpia.
- Prompt injection: el texto y el catálogo se tratan como datos delimitados; la salida solo es una propuesta revisable.
- Límites: 5 por minuto y 30 por día por usuario (en caché de archivo; se reinician con `cache:clear`), máximo 400 ítems de catálogo, tiempo límite de 12 s (tope 15 s), un reintento solo ante error de conexión o 429/503/529 (no ante timeout de lectura).
- Auditoría `quote.assist_requested` con conteos, modelo, tokens, latencia y resultado; **nunca** el texto ni la respuesta.
- Errores: 503 `assistant_disabled` si está desactivado, 502 genérico si falla el proveedor (sin detalles).
- Proxy: `quotes/assist` con tiempo límite de 35 s solo para esa ruta y paso del 503 solo para ella.

## Cómo activarlo (paso manual, lo haces tú)

En el archivo de entorno privado del backend, define `ANTHROPIC_API_KEY` con tu clave y `AI_ASSISTANT_ENABLED=true` (el modelo por defecto es `claude-haiku-4-5-20251001`); luego `docker compose restart api`. Las claves disponibles están en `backend/.env.example`. La clave nunca va en Git ni en logs.

## Verificación

Pint aprobado; PHPUnit 238 aprobadas y 2 omitidas en SQLite en memoria, 240 en PostgreSQL `systek_test`; typecheck y build aprobados; E2E `assistant.spec.ts` (camino desactivado y proxy) aprobado en escritorio y móvil; revisión visual en 1440 y 390. Todas las pruebas usan `Http::fake()` con `preventStrayRequests`: **ninguna llamó al servicio real**. Auditoría de seguridad (Opus) sin hallazgos bloqueantes; se corrigieron descripciones sucias del catálogo, caracteres invisibles, reintento de timeouts y tope del tiempo límite.

## No verificado (requiere la clave real)

Calidad y tasa de SKU válidos del modelo con este esquema, aceptación del esquema por la API, tokens y costo reales (un catálogo de 400 ítems ≈ 12–15 k tokens de entrada por llamada), latencia real y formato de errores de Anthropic. Cuota solo por usuario, sin tope global. Al cambiar de familia la propuesta reemplaza las cláusulas seleccionadas (el aviso de confirmación lo indica). Sin `assist_request_id` en `POST /quotes` para medir aceptación.
