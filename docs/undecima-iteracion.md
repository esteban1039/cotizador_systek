# Iteración 11: base para la emisión oficial (sin emitir)

Diseño y decisiones: [diseno-iteracion-11.md](diseno-iteracion-11.md). Esta entrega **no habilita emisión, envío ni PDF oficial**; la aprobación interna sigue sin equivaler a emisión y nadie aprueba su propia cotización.

## Qué incluye

- **Empresa emisora versionada** (`companies`, `company_versions`): publicar crea versión nueva. La cuenta bancaria va cifrada (`encrypted:array`), es de solo escritura en la API (resumen enmascarado con los últimos 4 dígitos) y no aparece en auditoría, logs, instantáneas ni PDF de borrador. NIT con dígito de verificación validado en servidor. Los datos legales públicos se cargan con `InitialConfigurationSeeder` (idempotente); los datos bancarios los ingresa el administrador por `/empresa`.
- **Cláusulas por familia** (`clauses`, `clause_versions` inmutables), administradas en `/clausulas`. Redacción inicial propuesta por Claude, editable por el administrador y pendiente de validación legal.
- **Coherencia de cláusulas**: bloquean siempre la cláusula de otra familia, histórica o inactiva, el texto idéntico al de otra familia y la cotización sin familia; falta de pago, garantía o vigencia advierte al enviar y bloquea al aprobar.
- **Numeración** `COT-AAAA-####` por raíz (contador transaccional sin huecos), con versión visible `V1`, `V2`; las revisiones heredan el número; backfill reversible.
- **ReteIVA** 15 % sobre el IVA total para clientes agentes retenedores (`clients.withholds_vat`, solo administrador, auditado). Aritmética entera, half-up, con instantánea `vat_withholding`; aprobar bloquea si cambió el indicador o la tarifa.
- **PDF de borrador** con número, versión, membrete público y total a pagar. Nunca datos bancarios ni firmante.

## Rutas nuevas

| Método | Ruta | Rol |
| --- | --- | --- |
| GET / POST | `/admin/company` | Administrador |
| GET / POST / PATCH | `/admin/clauses`, `/admin/clauses/{id}`, `/admin/clauses/{id}/versions` | Administrador |
| GET | `/clauses?family=` | Administrador, cotizador |
| PATCH | `/clients/{id}/tax-profile` | Administrador |

Todas figuran en la allowlist del proxy Nuxt.

## Verificación

Línea base `883d580`: Pint, typecheck y build aprobados; PHPUnit 192 aprobadas y 2 omitidas en SQLite en memoria, 194 en PostgreSQL `systek_test`. Auditoría de seguridad (Opus, lectura de código) sin hallazgos bloqueantes; se corrigieron dos de severidad baja: `clear_bank_account` numérico (`1`) se ignoraba y `bank_account: {}` provocaba 500 (ahora 422), con prueba de regresión.

## Pendiente / no verificado

- Numeración concurrente, backfill y rollback de migraciones: cubiertos por pruebas, no reauditados en la revisión de seguridad.
- E2E en `systek_e2e` no se ejecutó en esta entrega.
- Cláusulas y ReteIVA sujetas a validación legal y contable. Sin umbral de base mínima en UVT (provisional).
- Opcional: throttle en escrituras de cláusulas y `tax-profile`; filtrar dígitos largos en `reason` de empresa.
- Datos bancarios aún sin cargar: los ingresa el administrador por la interfaz.
