---
name: review
description: Revisión de código independiente del cotizador sobre cambios recientes, archivos o un módulo indicado. Úsalo cuando el usuario pida revisar código o antes de cerrar una entrega.
argument-hint: "[archivos, módulo o descripción del cambio]"
---

# Proceso: revisión de código

Alcance: $ARGUMENTS

1. **Alcance:** lo indicado por el usuario, `git diff`/`git status` si hay commits, los archivos modificados en la sesión o `find backend/app backend/routes backend/database frontend/app frontend/server -newer <referencia> -type f`.
2. **Riesgo:** crítico (dinero, precios, aprobación, roles, MFA, proxy, migraciones con datos, integraciones) → `final-reviewer` con `model: opus`; normal → `final-reviewer`.
3. Si toca autenticación, autorización, proxy, archivos o secretos: `security-reviewer` después del revisor.
4. Entrega el veredicto consolidado: bloqueantes con `archivo:línea`, no bloqueantes y lo no verificado.
5. No corrijas nada salvo que el usuario lo pida; si lo pide, pasa a `/bugfix` o `/refactor`.
