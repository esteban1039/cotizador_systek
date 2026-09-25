---
name: security-audit
description: Proceso de seguridad del cotizador — auditoría de un módulo o corrección de una vulnerabilidad (autenticación, roles, IDOR, MFA, proxy Nuxt, importación, PDF, secretos, rate limiting). Distinto de /security-review.
argument-hint: "<módulo a auditar o vulnerabilidad sospechada>"
---

# Proceso: seguridad

Pedido: $ARGUMENTS

1. `security-reviewer` (`model: opus` si abarca auth, MFA o secretos): hallazgos CONFIRMADO/PLAUSIBLE con severidad y corrección. Si es solo auditoría, entrega el informe y termina.
2. Por cada hallazgo confirmado a corregir: corrección (tú o especialista) con una prueba que demuestre el ataque antes y su bloqueo después.
3. `security-reviewer` re-verifica → `final-reviewer` con `model: opus`.
4. **Reglas:** no inventar vulnerabilidades; no exponer secretos en el informe; no desactivar controles existentes (guardia de pruebas, allowlist, throttling, `no-store`).
5. **Terminado:** cada corrección con prueba, re-verificación sin bloqueantes, revisión aprobada.
