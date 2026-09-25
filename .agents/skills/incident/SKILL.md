---
name: incident
description: Proceso para incidentes (la aplicación no arranca, 5xx/502 generalizados, login roto, datos inconsistentes, contenedores caídos, pérdida de acceso). Investigar antes de modificar.
argument-hint: "<síntoma, desde cuándo, a quién afecta>"
---

# Proceso: incidente

Síntoma: $ARGUMENTS

**Primero investigar, después modificar.** Ningún cambio de código, datos o contenedores sin evidencia.

1. **Estabilizar sin destruir:** si hay riesgo de datos, `scripts/backup-local-db.sh` antes de cualquier acción.
2. **Evidencia:** empieza tú con lo barato (`docker compose ps`, `docker compose logs --tail=200 api`, `grep` acotado en `laravel.log`). Si la causa no aparece, `incident-investigator` (Sonnet).
3. **Escalar a Opus** (`incident-investigator` con `model: opus`) solo con evidencia contradictoria, varios sistemas implicados o riesgo crítico. `security-reviewer` si hay indicios de acceso indebido o tokens filtrados.
4. **Corrección:** con causa respaldada, corrección mínima + prueba de regresión. Mitigaciones operativas (reiniciar, reconstruir imagen) solo con aprobación del usuario.
5. `qa` → `final-reviewer` (`model: opus` si hubo datos, acceso o dinero).
6. **Terminado:** servicio verificado, causa raíz explicada, prueba de regresión, datos afectados identificados, acciones preventivas propuestas.
