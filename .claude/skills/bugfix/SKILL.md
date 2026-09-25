---
name: bugfix
description: Proceso para corregir un bug del cotizador (algo que no funciona como debería). Distingue bug simple de complejo.
argument-hint: "<síntoma, pasos, rol, mensaje de error>"
---

# Proceso: corrección de bug

Reporte: $ARGUMENTS

1. **Reproducir:** síntoma, pasos, rol, mensaje exacto. Pregunta solo lo imprescindible.
2. **Simple** (causa localizable leyendo el código, una capa): escribe primero una prueba que falle, luego la corrección mínima. Sin subagentes; `qa` solo si el área es de riesgo.
3. **Complejo** (causa desconocida, intermitente, 502 sin detalle, datos/dinero inconsistentes, concurrencia, un intento fallido): `incident-investigator` → corrección mínima con prueba que reproduce (tú o especialista) → `qa` → `final-reviewer` (`model: opus` si toca dinero, permisos o datos).
4. **Reglas:** corrección mínima, sin refactors oportunistas. Datos existentes dañados: propón script/migración de reparación y espera aprobación.
5. **Terminado:** la prueba de regresión falla antes y pasa después, validaciones verdes, causa raíz explicada al usuario.
