---
name: database-change
description: Proceso para cambios de esquema o datos en PostgreSQL del cotizador (tablas, columnas, índices, constraints, backfills), con migraciones reversibles y respaldo previo.
argument-hint: "<cambio de datos o esquema>"
---

# Proceso: cambio de base de datos

Pedido: $ARGUMENTS

1. **Clasificar:**
   - Aditivo simple (columna/índice sin efecto en datos): tú o `dba-postgresql`, sin architect.
   - Estructural (cotizaciones, versiones de precio, usuarios; dividir/fusionar tablas; claves; backfill con lógica de negocio): `architect` con la pregunta concreta primero.
   - Destructivo (borrar columnas/tablas/datos): describe el impacto y pide aprobación explícita antes de seguir.
2. **Orden:** `dba-postgresql` (migración nueva y reversible, índices, efecto sobre filas existentes) → backend (modelo, repositorio, API, pruebas) → `qa`: SQLite **y** `systek_test`; `migrate` + `migrate:rollback` solo en `systek_test`.
3. **Revisión:** `final-reviewer` con `model: opus` si hay datos existentes, dinero o cambio estructural; si no, sin override.
4. **Aplicar en desarrollo:** solo con el usuario informado: `scripts/backup-local-db.sh` y luego `docker compose run --rm api php artisan migrate`. Nunca `migrate:fresh|reset`, `db:wipe`, `DROP` o `TRUNCATE`.
5. **Terminado:** migración y rollback verificados en base de prueba, ambos motores en verde, docs al día si cambia la API.
