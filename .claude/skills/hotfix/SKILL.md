---
name: hotfix
description: Proceso rápido para una corrección urgente y acotada del cotizador cuya causa ya se conoce. Mantiene prueba de regresión y validación mínima.
argument-hint: "<problema urgente y causa conocida>"
---

# Proceso: hotfix

Pedido: $ARGUMENTS

Si la causa NO es conocida, usa `/incident` o `/bugfix`.

1. Corrección mínima + prueba de regresión (tú o el especialista del área). Sin refactors ni mejoras adicionales.
2. Validación mínima obligatoria: prueba nueva + pruebas del área + Pint/typecheck (hook de Stop).
3. `final-reviewer` solo si toca dinero, aprobación, permisos, sesión o datos (con `model: opus`).
4. Registra la deuda pendiente (solución completa) para un `/bugfix` o `/refactor` posterior.
