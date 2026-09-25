---
name: performance
description: Proceso para problemas de rendimiento del cotizador (tablero, listados, catálogo, cálculo, PDF, importación, páginas Nuxt lentas). Mide antes y después.
argument-hint: "<qué es lento, cuánto tarda, con qué datos>"
---

# Proceso: rendimiento

Pedido: $ARGUMENTS

1. **Medir:** `performance` (o tú si es evidente): tiempo, número de consultas, `EXPLAIN ANALYZE` (solo SELECT), causa raíz y propuesta.
2. Solución estructural (caché compartida, colas, desnormalización, cambio de modelo): `architect` con la pregunta concreta antes de implementar.
3. **Implementar** según la causa (índices → `dba-postgresql`; N+1, paginación o cálculo → backend; carga o bundle → frontend), secuencial.
4. **Verificar:** regresión funcional y nueva medición con el mismo método. `final-reviewer` si tocó dinero, bloqueos o esquema.
5. **Reglas:** nunca sacrificar bloqueos, inmutabilidad ni autorización por velocidad; medir en entornos locales, sin escrituras sobre `systek`.
6. **Terminado:** mejora medida (antes/después), pruebas verdes.
