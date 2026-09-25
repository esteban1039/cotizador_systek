---
name: iteracion11-decisiones-usuario
description: Decisiones de negocio del usuario (2026-09-23) para la Iteración 11: ReteIVA, datos de empresa emisora, datos bancarios fuera del código, cláusulas redactadas por el equipo
metadata:
  type: project
---

Decisiones confirmadas por el usuario el 2026-09-23 (vía orquestador), base de la Iteración 11 (diseño en docs/diseno-iteracion-11.md):

- IVA 19 % sigue viniendo de la versión de precio por ítem. ReteIVA = 15 % del IVA, solo si el cliente es agente retenedor (indicador por cliente, editable solo por admin). Las cotizaciones guardadas no cambian: la instantánea registra si aplicó y la tasa.
- Empresa emisora: el usuario entregó datos oficiales (razón social, NIT con DV verificado, dirección, celular, correo, web, firmante y cargo). Se cargan con un seeder idempotente que nunca sobrescribe lo que editó el admin. No se escriben en migraciones.
- **Los datos bancarios nunca van en código, seeders, migraciones, pruebas ni docs.** Solo se ingresan por la UI de admin, cifrados (cast encrypted). La auditoría solo dice "Datos bancarios actualizados".
- El usuario autorizó que el equipo redacte las cláusulas por familia (7 familias; pago contado / 50-50 / 60-40; vigencia de 15 días), cargadas como primera versión "redacción inicial propuesta".

**Why:** plan maestro §2.4/§13. Antes estaba prohibido inventar datos legales; estas autorizaciones son explícitas y acotadas.

**How to apply:** no pedir de nuevo estos datos. Seguir tratando cualquier dato bancario como secreto (usar valores ficticios en las pruebas). Las decisiones provisionales de diseño (clave `payable`, backfill de numeración, reglas de bloqueo/advertencia) quedan en §9 del doc de diseño hasta que el usuario las confirme; verifica su estado allí o en el código antes de asumirlas.
