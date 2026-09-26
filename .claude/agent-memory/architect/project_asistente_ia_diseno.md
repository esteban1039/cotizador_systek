---
name: asistente-ia-diseno
description: Diseño acordado del asistente IA v1 (POST /quotes/assist, solo propuesta, Haiku 4.5, sin esquema, datos mínimos)
metadata:
  type: project
---

Asistente IA v1 (diseñado 2026-09-25): `POST /api/v1/quotes/assist` devuelve una propuesta (familia, partidas por SKU+cantidad, alcance, exclusiones) y NO guarda nada; el editor la carga y se guarda por el flujo normal (preview/store).
Contrato `Application/Quotes/QuoteDraftAssistantClient` + `Infrastructure/Anthropic/*` (patrón Drive, cliente HTTP de Laravel, tool use forzado). Validación estricta en Domain puro; `discount_bps` siempre 0; la IA nunca recibe precios, uuids ni clientes.
Sin tabla nueva: cuota por RateLimiter (minuto + día) y auditoría solo con métricas (sin texto).
Hallazgo: el proxy Nuxt tiene timeout 15 s y convierte 503 en 502; hay que ajustar ambos solo para `quotes/assist`.

**Why:** decisiones del usuario: datos mínimos a Anthropic, desactivado por defecto, clave en env, sin dependencias nuevas, revisión humana obligatoria.
**How to apply:** en IA v2 (antecedentes, cliente candidato) partir de aquí; no reabrir el "solo propuesta, nunca guarda". Ver [[iteracion11-decisiones-usuario]].
