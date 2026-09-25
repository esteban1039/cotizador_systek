# Optimización del harness de Claude Code

Optimiza el harness existente. NO lo reconstruyas y NO cambies funcionalidad de la aplicación.
Prioridades: 1) precisión, 2) ahorro de tokens, 3) velocidad. No tengo afán.

IMPORTANTE: este documento es una instrucción para ti, no contenido para copiar.
No pegues estas secciones en CLAUDE.md, agentes ni skills. Tradúcelas a configuración mínima.

## Fase 0 — Preparación (antes de editar nada)

1. Crea una rama git nueva: `chore/harness-optimization`.
2. Ejecuta `/context` y guarda la línea base: tokens de system prompt, CLAUDE.md, MCP, skills, agentes y memoria.
3. Lee: CLAUDE.md, .claude/settings.json, .claude/agents/, .claude/skills/, .claude/workflows/, .claude/hooks/, .mcp.json.
4. Preséntame un plan por archivo (qué cambia y por qué) y ESPERA mi aprobación.

## Fase 1 — Modelos y effort

- settings.json: `"model": "sonnet"`. Nunca `default` ni `opusplan` como valor habitual.
- Nada de `xhigh` o `max` en configuración permanente.
- Configuración de agentes (ajusta si el proyecto lo justifica):

| Agente | model | effort | maxTurns | omitClaudeMd |
|---|---|---|---|---|
| explorer (si existe uno custom) | haiku | low | 4 | true |
| architect | opus | medium | 6 | false |
| backend / frontend | sonnet | medium | 12 | false |
| dba / devops | sonnet | medium | 8 | false |
| integrations | sonnet | medium | 10 | false |
| qa | sonnet | medium | 8 | true |
| security | sonnet | high | 6 | true |
| final-reviewer | sonnet | medium | 6 | true |
| incident-investigator | sonnet | high | 8 | false |

- Si el explorer custom solo hace búsquedas, evalúa eliminarlo y usar el agente Explore nativo.
- Si hay agentes duplicados o que nunca se usan, propón eliminarlos.

## Fase 2 — Contexto de los agentes

- Elimina `skills:` de todos los agentes salvo que sea imprescindible (precarga la skill completa).
  Skills como deploy, migration, security-review, incident y release se cargan solo cuando aplica.
- Elimina `memory:` de qa, security, final-reviewer y explorer. Consérvalo solo donde aporte algo concreto y dime dónde.
- Cada agente debe terminar su system prompt con este formato de salida:
  RESULTADO / ARCHIVOS / CAMBIOS-HALLAZGOS / PRUEBAS / RIESGOS. Sin repetir el requerimiento ni copiar código completo.
- QA recibe requerimiento + diff + comandos de prueba. No explora el repositorio.

## Fase 3 — CLAUDE.md

- Máximo ~80 líneas. Solo: arquitectura esencial, comandos principales, convenciones críticas, reglas de seguridad.
- Mueve instrucciones especializadas a skills. Elimina duplicados entre CLAUDE.md, agentes, workflows y skills.
- Añade una sección "Política de agentes" de MÁXIMO 25 líneas con esta lógica:
  - SIMPLE: sin subagentes. Implementar y verificar directamente.
  - MEDIUM: 1 especialista Sonnet (+ QA si aplica).
  - COMPLEX: Architect Opus solo si hay pregunta arquitectónica → especialista → QA → reviewer. Máximo 3 subagentes.
  - CRITICAL (auth, pagos, cripto, secretos, corrupción de datos): puede sumar Security Opus y Final Reviewer Opus.
  - Siempre secuencial. Nunca Agent Teams salvo que yo lo pida.
  - Antes de invocar Opus: formular la pregunta concreta que debe resolver. Sin pregunta concreta, no hay Opus.
  - Incidentes: empezar con Sonnet; escalar a Opus solo si hay evidencia contradictoria, varios sistemas o riesgo crítico.
  - Handoff entre agentes: resumen compacto (archivos, decisiones, cambios, pruebas pendientes). Nadie re-explora desde cero.
  - Búsqueda: Grep/Glob/símbolos → fragmento → archivo completo solo si es necesario. Ignorar vendor, node_modules, dist, build, coverage, storage/logs, caches.
- Añade instrucciones de compactación breves: conservar requerimiento, decisiones, archivos modificados, errores abiertos y resultados de pruebas; descartar exploraciones, logs e intentos descartados.

## Fase 4 — Hooks y comandos

- Todo lo determinístico (lint, format, typecheck, tests, secretos, build) va en hooks o scripts, no en agentes.
- Los hooks deben ser silenciosos cuando pasan: una línea de resumen. Si fallan: test fallido, error y stack relevante (máx. ~30 líneas).
- Revisa los hooks existentes y señala cuáles devuelven salida grande al contexto.
- Tests incrementales: específicos → módulo → suite completa solo si el riesgo lo justifica.

## Fase 5 — MCP

- Lista cada MCP de .mcp.json con su uso real. Propón deshabilitar los que tengan alternativa CLI o se usen poco.

## Fase 6 — Validación e informe

Ejecuta `/context` de nuevo y entrégame en formato compacto:

1. Tokens antes/después por categoría (de `/context`).
2. Agentes modificados: modelo, effort, maxTurns, memory y skills antes → después.
3. Skills que quedaron lazy-load y memorias eliminadas.
4. MCP deshabilitados.
5. Líneas de CLAUDE.md antes → después.
6. Los 3 mayores consumidores de tokens que encontraste y qué hiciste con cada uno.
7. Cualquier cambio que propones pero NO aplicaste, con la razón.

## Notas para mí (no para aplicar)

- Ejecutar `/clear` al terminar cada requerimiento independiente.
- Revisar `/context` cada cierto tiempo para detectar regresiones.
