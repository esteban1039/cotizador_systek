---
name: refactor
description: Proceso para refactorizaciones del cotizador (local o transversal) y migraciones tecnológicas o actualizaciones mayores de dependencias (Laravel, PHP, Nuxt, PostgreSQL). Preserva comportamiento.
argument-hint: "<qué reestructurar o actualizar y por qué>"
---

# Proceso: refactor / migración tecnológica

Pedido: $ARGUMENTS

- **Local** (un archivo/clase/componente, sin cambiar contratos): hazlo tú y verifica.
- **Transversal** (varias capas o contratos): `architect` define pasos incrementales que mantienen las pruebas verdes → especialista(s), secuencial → `qa` → `final-reviewer`.
- **Migración tecnológica / dependencias:** requiere aprobación del usuario. `architect` (rupturas, etapas, reversión, documentación oficial) → `devops-docker` y/o especialista → `qa` → `final-reviewer` con `model: opus`.

**Reglas:** sin cambios de comportamiento; las pruebas existentes no se modifican salvo renombres mecánicos justificados; se mantienen `RepositoryArchitectureTest`, la guardia de base de pruebas, las transacciones y el orden de bloqueos.

**Terminado:** suite completa verde antes y después, typecheck/build verdes, revisión aprobada.
