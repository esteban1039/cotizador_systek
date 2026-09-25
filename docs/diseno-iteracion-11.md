# Diseño — Iteración 11: base para la emisión oficial (sin emitir)

Estado: **implementada** (ver [undecima-iteracion.md](undecima-iteracion.md)); las decisiones de la sección 9 se aplicaron con su valor provisional. No habilita emisión, envío ni PDF oficial. Nada de este documento cambia la regla "nadie aprueba su propia cotización" ni la de "aprobación interna ≠ emisión".

Alcance:

1. Empresa emisora versionada, con datos de pago cifrados y de solo escritura.
2. Cláusulas por familia, versionadas, con redacción inicial propuesta cargada por un seeder idempotente.
3. Validación de coherencia de cláusulas al enviar a revisión y al aprobar.
4. Numeración `COT-AAAA-####` por raíz, con versión visible `V1`, `V2`.
5. ReteIVA (15 % sobre el IVA) para clientes agentes retenedores, con instantánea en la cotización.

Decisiones del usuario incorporadas (2026-09-23):

- IVA 19 %: sigue viniendo de la versión de precio.
- ReteIVA: 15 % sobre el IVA, solo para clientes agentes retenedores.
- Cláusulas: se autoriza redactar las cláusulas iniciales por familia.
- Datos oficiales de la empresa emisora, que se cargan por seeder idempotente:
  - razón social Systek Company S.A.S.;
  - NIT 901704107-1 (DV verificado);
  - dirección Cra 75 # 28-21, Belén, Medellín;
  - celular 3045869886;
  - correo stip@systekcompany.io;
  - sitio web https://systekcompany.io;
  - firmante Jhonatan Stip Gutierrez, cargo Gerente.
- **Datos bancarios:** el usuario los tiene, pero no van en código, seeder, migraciones, pruebas ni documentación. Se ingresan solo por la interfaz de administración.

---

## 1. Resumen de decisiones

| Tema | Decisión | Motivo |
| --- | --- | --- |
| Familias | Se mantiene la cadena en `catalog_items.family` / `commercial_rules.family`; lista controlada centralizada en el enum `App\Domain\QuoteFamily`. **Sin tabla `quote_families`.** | La lista es estática, no hay requisito de administrarla; una tabla exigiría FKs y migrar tres tablas con datos. El enum elimina la constante duplicada `CatalogController::FAMILIES`. |
| Familia de la cotización | Campo explícito `family` en la cotización (obligatorio al guardar desde esta iteración). | Plan §5.1 "una familia seleccionada". Las partidas pueden mezclar familias (CCTV con cableado); la familia explícita decide qué cláusulas son coherentes. |
| Empresa emisora | Tabla de identidad `companies` + versiones inmutables `company_versions` (publicar crea versión, como `PriceVersion`). Cuenta bancaria estructurada (banco, tipo, número, titular opcional) en una sola columna cifrada (`encrypted:array`, sin dependencias), de solo escritura en la API: el admin ve un resumen enmascarado y confirma el número al ingresarlo. NIT con validación de DV (DIAN) en servidor. Cargo del firmante obligatorio para "completa"; sitio web opcional. Carga inicial de datos oficiales por seeder idempotente: tras ejecutarlo solo falta la cuenta bancaria. | La futura emisión debe referenciar exactamente la versión usada. La auditoría no puede guardar valores bancarios, así que el historial vive en versiones, no en diffs de auditoría. |
| Cláusulas | `clauses` (identidad: familia, tipo, título, predeterminada, activa) + `clause_versions` inmutables. Varias cláusulas por familia/tipo (p. ej. tres formas de pago), una predeterminada. Sin familia "general" en esta iteración. | Análogo a ítem → versión de precio. Las formas de pago contado / 50-50 / 60-40 son alternativas reales por familia. |
| Instantánea | El texto usado sigue en los campos de la instantánea (`scope`, `exclusions`, `payment_terms`, `warranty`, + nuevos `validity_terms`, `observations`). Se agrega `clauses[]` con la procedencia (cláusula, versión, hash del texto publicado, `modified`). | El cotizador puede ajustar el texto; lo que se guarda es lo que se usó. Las versiones de cláusula nunca se editan ni borran. |
| Coherencia | Bloquea (envío y aprobación): cláusula de otra familia, cláusula histórica o inactiva, texto idéntico a una cláusula de otra familia, cotización sin familia. Bloquea solo al aprobar (advierte al enviar): falta cláusula de pago, garantía o vigencia. Advierte siempre: texto modificado respecto a la cláusula, partidas de otra familia. | Ver §4.4. |
| Numeración | Contador por año en `quote_number_sequences`, asignado dentro de la transacción de creación de la raíz con `insertOrIgnore` + `lockForUpdate`. Las revisiones heredan el número. Backfill reversible de cotizaciones existentes. | Seguro en PostgreSQL, funcional en SQLite, y **sin huecos por rollback** (el contador es transaccional, a diferencia de una `SEQUENCE`). |
| ReteIVA | Sobre el **IVA total** de la cotización, una sola vez, half-up a centavos, en `QuoteCalculator`. Tasa en `config/quotes.php` (`QUOTE_VAT_WITHHOLDING_BPS`, por defecto 1500). Indicador `clients.withholds_vat` (admin, auditado). La instantánea guarda `vat_withholding: {applied, rate_bps, basis}`. | Base legal = IVA de la factura; un único redondeo evita acumular diferencias por partida y coincide con el cálculo que hará el cliente. |
| PDF de borrador | Muestra número COT + versión, membrete público (razón social, NIT, dirección, teléfono, correo y sitio web si existen) y ReteIVA/total a pagar. **Nunca** datos bancarios ni firmante. Nombre de archivo `COT-AAAA-####-Vn-borrador.pdf`. | Un borrador con datos bancarios o firma parece un documento emitido y puede circular por error; esos datos se insertarán solo en la emisión oficial. |

---

## 2. Impacto

**Dominios:** Cotizaciones (cálculo, instantánea, revisión, PDF), Catálogo (familias), Clientes (indicador fiscal), Configuración nueva (empresa, cláusulas), Auditoría.

**No cambia:** redondeo por partida bruto → descuento → IVA; `tax_bps` por versión de precio; publicación de precios; `QuoteVisibility` (costos/margen); estados y transiciones; autenticación/MFA; importación histórica; `TestDatabaseSafety`; `RepositoryArchitectureTest` (solo se amplía).

### Tablas

| Tabla | Cambio |
| --- | --- |
| `companies` | Nueva |
| `company_versions` | Nueva |
| `clauses` | Nueva |
| `clause_versions` | Nueva |
| `quote_number_sequences` | Nueva |
| `quotes` | + `quote_number` (nullable) + único `(quote_number, revision_number)` + backfill |
| `clients` | + `withholds_vat` boolean default false |

### Archivos backend (nuevos ▲ / modificados ●)

- ▲ `database/migrations/2026_09_23_000001_create_company_profiles.php`
- ▲ `database/migrations/2026_09_23_000002_create_clauses.php`
- ▲ `database/migrations/2026_09_23_000003_add_quote_numbers.php`
- ▲ `database/migrations/2026_09_23_000004_add_vat_withholding_to_clients.php`
- ▲ `app/Models/Company.php`, `CompanyVersion.php`, `Clause.php`, `ClauseVersion.php`
- ▲ `config/quotes.php`
- ▲ `app/Domain/QuoteFamily.php`, `app/Domain/CompanyProfile.php`
- ▲ `app/Domain/Quotes/ClauseType.php`, `ClauseText.php`, `ClauseSelection.php`, `ClauseCoherence.php`, `VatWithholdingPolicy.php`, `SnapshotCompatibility.php`
- ● `app/Domain/Quotes/QuoteCalculator.php`, `QuotePricer.php`, `ApprovalValidation.php`
- ▲ `app/Repositories/Contracts/CompanyRepository.php`, `ClauseRepository.php`, `QuoteNumberRepository.php` + implementaciones `Eloquent*`
- ● `app/Repositories/Contracts/QuoteRepository.php`, `ClientRepository.php`, `DashboardRepository` (impl.) + implementaciones
- ▲ `app/Application/Company/PublishCompanyProfile.php`
- ▲ `app/Application/Quotes/CreateClause.php`, `PublishClauseVersion.php`, `UpdateClause.php`
- ● `app/Application/Quotes/CreateQuote.php`, `GenerateQuotePdf.php`
- ▲ `app/Http/Controllers/CompanyController.php`, `ClauseController.php`
- ● `ClientController.php`, `QuoteController.php`, `QuoteReviewController.php`, `CatalogController.php`, `HistoryController.php` (usar `QuoteFamily`)
- ● `app/Http/Requests/PreviewQuoteRequest.php`, `CalculateQuoteRequest.php`
- ● `app/Http/Middleware/AuthNoStore.php`, `bootstrap/app.php` (no-store y `dontFlash`)
- ● `app/Providers/AppServiceProvider.php` (bindings)
- ● `routes/api.php`
- ● `resources/views/quotes/draft-pdf.blade.php`
- ▲ `database/seeders/InitialConfigurationSeeder.php`
- ● `tests/Feature/RepositoryArchitectureTest.php` (ampliar glob a `Application/Company`)

### Archivos frontend

- ● `server/api/backend/[...path].ts` (allowlist + nombre de PDF)
- ● `shared/types.ts`, `shared/admin.ts`
- ▲ `app/pages/empresa.vue`, `app/pages/clausulas.vue`
- ● `app/pages/index.vue` (editor), `borradores/[id].vue`, `borradores/index.vue`, `inicio.vue`, `clientes.vue`, `auditoria.vue`
- ● `app/components/QuoteTotals.vue`
- ● `app/middleware/auth.global.ts`, `app/app.vue` (rutas admin y navegación; la lista está duplicada en ambos)
- ● `tests/*.spec.ts` (payloads, nombre de PDF) + nuevos specs

---

## 3. Base de datos

Migraciones nuevas y reversibles. Antes de migrar la base de desarrollo: `scripts/backup-local-db.sh`. La migración 3 modifica datos existentes (backfill).

### 3.1 `2026_09_23_000001_create_company_profiles`

```
companies
  id            uuid PK
  code          string(40) UNIQUE        -- 'issuer'; la fila se crea bajo demanda (insertOrIgnore), la migración no inserta datos
  timestamps

company_versions
  id              uuid PK
  company_id      uuid FK companies RESTRICT
  version         unsigned int
  status          string(20)             -- current | historical
  legal_name      string(200) NOT NULL
  trade_name      string(100) NULL        -- marca del encabezado; si falta, el PDF usa "SYSTEK"
  nit             string(20)  NULL        -- formato "901704107-1", DV validado
  address         string(255) NULL
  phone           string(40)  NULL
  email           string(255) NULL
  website         string(255) NULL        -- solo https
  signer_name     string(150) NULL
  signer_title    string(150) NULL        -- obligatorio para "completa"
  bank_account    text NULL               -- JSON cifrado (cast encrypted:array, APP_KEY):
                                          -- {bank_name, account_type: savings|checking, account_number, account_holder|null}
  origin          string(20) default 'admin'   -- initial_load | admin
  reason          text
  published_by    bigint FK users NULL RESTRICT  -- NULL = Sistema (seeder)
  timestamps
  UNIQUE (company_id, version)
  INDEX  (company_id, status)
```

`down()`: `dropIfExists('company_versions')`, `dropIfExists('companies')`.

Modelo `CompanyVersion`: `$casts = ['bank_account' => 'encrypted:array']`, `$hidden = ['bank_account']`. Los repositorios construyen arreglos explícitos; nunca devuelven `getAttributes()` completos de esta tabla. Una sola columna cifrada (no cuatro) evita fugas parciales y simplifica copiarla entre versiones. La migración **no** inserta datos de empresa.

### 3.2 `2026_09_23_000002_create_clauses`

```
clauses
  id          uuid PK
  family      string(40)      -- valor de QuoteFamily
  type        string(30)      -- scope_base | exclusions | payment | warranty | validity | observations
  title       string(120)
  is_default  boolean default false
  active      boolean default true
  is_demo     boolean default false
  created_by  bigint FK users NULL RESTRICT
  timestamps
  UNIQUE (family, type, title)
  INDEX  (family, active)
  UNIQUE PARCIAL clauses_one_default_per_family_type ON (family, type) WHERE is_default

clause_versions
  id            uuid PK
  clause_id     uuid FK clauses RESTRICT
  version       unsigned int
  status        string(20)    -- current | historical
  body          text
  body_hash     char(64)      -- sha256 de ClauseText::normalize(body)
  origin        string(20) default 'admin'   -- initial_draft | admin
  reason        text
  published_by  bigint FK users NULL RESTRICT
  timestamps
  UNIQUE (clause_id, version)
  INDEX  (clause_id, status)
  INDEX  (body_hash)
```

El índice parcial se crea con `DB::statement('CREATE UNIQUE INDEX clauses_one_default_per_family_type ON clauses (family, type) WHERE is_default')` (sintaxis válida en PostgreSQL y SQLite). Es red de seguridad; la regla se aplica bajo bloqueo en el repositorio. `down()`: `dropIfExists('clause_versions')`, `dropIfExists('clauses')`.

### 3.3 `2026_09_23_000003_add_quote_numbers`

```
quote_number_sequences
  year         unsigned smallint PK
  last_number  unsigned int default 0
  timestamps

quotes
  + quote_number  string(20) NULL
  + UNIQUE (quote_number, revision_number)     -- NULL permitido múltiples veces en PG y SQLite
```

Backfill en PHP dentro de `up()` (no SQL de fechas específico del motor):

1. Raíces (`root_quote_id IS NULL`) ordenadas por `created_at, id`, en bloques.
2. Año = año de `created_at` (se almacena en hora de Bogotá, `config/app.php`).
3. Asigna `COT-{año}-{n:04d}` consecutivo por año; copia el número a sus revisiones (`root_quote_id = raíz`).
4. Inserta/actualiza `quote_number_sequences` con el máximo por año.

No modifica `snapshot`. `down()`: elimina el índice único, la columna y la tabla. Reaplicar `up()` reasigna los mismos números mientras no se hayan creado cotizaciones nuevas entre medias (documentar).

### 3.4 `2026_09_23_000004_add_vat_withholding_to_clients`

`clients + withholds_vat boolean default false`. Clientes existentes quedan en `false`. `down()`: `dropColumn('withholds_vat')`.

### 3.5 Orden de bloqueos (ampliado)

Se conserva ítem → precio, usuario → tokens, cotización → estado. Nuevo orden global dentro de una transacción:

**cotización raíz → cliente → ítem → precio → regla → cláusula → versión de cláusula → contador de numeración**

- Publicar cláusula: cláusula (FOR UPDATE) → versiones.
- Cambiar predeterminada: todas las cláusulas de `(family, type)` FOR UPDATE ordenadas por `id` (evita interbloqueo entre dos cambios simultáneos).
- Publicar empresa: `companies` (FOR UPDATE) → versiones. No participa en transacciones de cotización en esta iteración. En la futura emisión: cotización → empresa (lectura compartida).
- Indicador fiscal: cliente FOR UPDATE, sin otros bloqueos.
- El contador se bloquea al final de `CreateQuote`, justo antes del insert, para minimizar el tiempo retenido.

Agregar esta línea a `.claude/harness/invariantes.md` y a `docs/arquitectura.md` al cerrar la iteración.

---

## 4. Dominio, casos de uso y repositorios

### 4.1 Tipos de dominio

- `App\Domain\QuoteFamily` (enum string): `cctv`, `data_power`, `equipment`, `software`, `ups`, `security`, `services`. `values()`. Sustituye `CatalogController::FAMILIES` en catálogo, reglas e históricos.
- `App\Domain\Quotes\ClauseType` (enum string) con: campo de instantánea, longitud máxima, obligatoriedad.

| Tipo | Campo de la cotización | Máx. | Obligatoria para aprobar |
| --- | --- | --- | --- |
| `scope_base` | `scope` | 5000 | no |
| `exclusions` | `exclusions` | 5000 | no |
| `payment` | `payment_terms` | 1000 | **sí** |
| `warranty` | `warranty` | 1000 | **sí** |
| `validity` | `validity_terms` (nuevo) | 1000 | **sí** |
| `observations` | `observations` (nuevo) | 5000 | no |

- `App\Domain\Quotes\ClauseText`: `normalize()` (trim, colapsar espacios en blanco, `mb_strtolower`), `hash()` (sha256 del normalizado), `render(body, validityDays)` (único marcador permitido: `{vigencia_dias}`, reemplazo literal con `str_replace`; sin motor de plantillas). Al publicar se rechaza cualquier otro `{token}` y se rechazan secuencias de 8 o más dígitos (ignorando espacios, puntos y guiones) con el mensaje "Los datos bancarios se configuran en Empresa emisora, no en cláusulas."
- `App\Domain\CompanyProfile::missing(?array $profile): list<string>` — campos obligatorios para la emisión futura: `legal_name, nit, address, phone, email, signer_name, signer_title, bank_account`. `trade_name` y `website` son opcionales. Sin versión ⇒ todos faltan. Tras el seeder ⇒ solo `bank_account`.
- `App\Domain\Nit` — `checkDigit(string $base): int` con el algoritmo DIAN (pesos 3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71 desde el dígito menos significativo; `r = suma mod 11`; DV = `r` si `r ∈ {0, 1}`, si no `11 − r`) y `normalize(string): ?string`, que acepta puntos, espacios y guión y devuelve `"#########-D"` o `null` si el DV no coincide. Caso de prueba: `901704107` → DV 1 (suma 661, 661 mod 11 = 1). Por ahora solo se usa para la empresa emisora; el NIT de clientes no cambia en esta iteración.
- `App\Domain\Quotes\VatWithholdingPolicy::rateFor(bool $clientWithholds): int` — lee `config('quotes.vat_withholding_bps')`, exige entero 0..10000 (si no, `RuntimeException`: falla visible, nunca silenciosa) y devuelve 0 si el cliente no retiene.
- `App\Domain\Quotes\SnapshotCompatibility::normalize(array $snapshot, object $record): array` — interpretación de lectura de instantáneas antiguas, **nunca se persiste**: agrega `family: null`, `validity_terms: null`, `observations: null`, `clauses: []`, `vat_withholding: {applied:false, rate_bps:0, basis:'tax_total'}`, `totals.vat_withholding: '0.00'`, `totals.payable: totals.total`, y toma `quote_number` de la columna. Se usa en `show`, `index`, PDF y `ApprovalValidation`.

### 4.2 ReteIVA en `QuoteCalculator`

Firma: `calculate(array $lines, int $vatWithholdingBps = 0): array`. Por partida no cambia nada. Tras sumar:

```
tax = totals['tax']                           // IVA total en centavos
q = intdiv(tax, 10000); r = tax % 10000
vat_withholding = q * bps + intdiv(r * bps + 5000, 10000)   // half-up exacto, sin desbordar
payable = totals['total'] - vat_withholding
```

- La descomposición evita desbordar `PHP_INT_MAX`: el IVA total puede llegar a ~1e16 centavos (100 partidas en el máximo), y `1e16 × 1500` desbordaría y PHP pasaría a float en silencio.
- `bps` fuera de 0..10000 o no entero → `InvalidArgumentException`.
- `totals` agrega `vat_withholding` y `payable` (cadenas decimales). `profit` no cambia (subtotal − costo).
- Supuesto documentado: todo `tax_bps` es IVA. Si en el futuro hay otros impuestos (p. ej. impoconsumo), habrá que separarlos antes de calcular la ReteIVA.
- `QuotePricer::calculate(array $lines, int $vatWithholdingBps = 0)` lo propaga.

Nombres en la respuesta (se conserva el contrato existente): `tax` = IVA, `total` = subtotal + IVA, `vat_withholding` = ReteIVA, `payable` = total a pagar (total − ReteIVA). Ver decisión 9.3.

### 4.3 Selección de cláusulas al guardar (`ClauseSelection`)

`resolve(string $family, array $clauseVersions, array $texts, int $validityDays): list<array>`, dentro de la transacción de `CreateQuote`:

1. Carga con bloqueo compartido (cláusula → versión) las versiones referenciadas (`ClauseRepository::lockedVersions`).
2. Para cada `tipo => version_id` exige: la versión existe, `status = current`, cláusula `active`, `clause.type === tipo`, `clause.family === family`. Si falla: `ValidationException` en `clause_versions.{tipo}`.
3. Construye la procedencia: `{type, clause_id, clause_version_id, version, family, title, body_hash, modified}`, donde `modified = hash(texto del campo) !== hash(render(body, validity_days))`.
4. Si se referencia `validity` u `observations`, el texto del campo no puede estar vacío.

### 4.4 Coherencia (`ClauseCoherence`) — bloqueo frente a advertencia

`check(array $snapshot, array $calculatedLines, bool $strict): array{flags: list<string>, clauses: list<array>}`. La invoca `ApprovalValidation::check()` (`$strict = $requireRules`: `false` al enviar, `true` al aprobar).

| Código | Condición | Enviar | Aprobar |
| --- | --- | --- | --- |
| E1 | Instantánea sin `family` (anterior a esta iteración) | bloquea | bloquea |
| E2 | Cláusula referenciada de otra familia | bloquea | bloquea |
| E3 | Versión referenciada histórica, o cláusula inactiva | bloquea | bloquea |
| E4 | Texto de un campo (excepto vigencia) cuyo hash coincide con una cláusula de **otra** familia y no con ninguna de la familia propia | bloquea | bloquea |
| M1 | Falta referencia a una cláusula vigente de `payment`, `warranty` o `validity` | advierte | bloquea |
| F1 | Texto modificado respecto a la cláusula publicada | advierte | advierte (el revisor lo justifica) |
| F2 | Partida de una familia distinta a la de la cotización | advierte | advierte |

Justificación:

- **Se bloquea lo objetivo y corregible**: una garantía de cámaras en una propuesta de datos/potencia (E2/E4, el caso del plan §2.4), una cláusula retirada o reemplazada (E3, análogo al precio histórico) o una instantánea sin familia (E1). Nada de eso es una excepción comercial legítima.
- **M1 sigue el patrón de las reglas comerciales** (`requireRules=false` al enviar): el cotizador no puede publicar cláusulas; bloquear el envío por configuración faltante detendría el flujo. Al aprobar sí bloquea (plan §5.1: pago, vigencia y garantía definidos).
- **Se advierte lo que puede ser legítimo**: textos ajustados (plan §5.2, "condición de pago no estándar" dispara revisión) y cotizaciones mixtas. Quedan registradas en `quote_reviews.validation.flags` con la justificación del revisor.
- E4 es un control determinista y barato, no un validador semántico: un cambio mínimo en el texto lo evade. El validador semántico queda para la fase de IA. Se excluye `validity` porque los textos de vigencia son iguales entre familias.

Mensajes (español), p. ej.: "La garantía corresponde a una cláusula de CCTV y la cotización es de Datos y potencia.", "La cláusula «Anticipo 50 % / 50 %» tiene una versión nueva (v3). Crea una nueva revisión.", "Falta la cláusula de forma de pago. La aprobación quedará bloqueada hasta incluirla.", "Esta cotización es anterior a las cláusulas por familia. Crea una nueva revisión."

### 4.5 `ApprovalValidation::check()` (orden dentro de la transacción)

1. Vigencia de la cotización (sin cambios).
2. Normaliza la instantánea (`SnapshotCompatibility`).
3. Cliente: `ClientRepository::lockedTaxProfile(client_id)` (bloqueo compartido). Si `withholds_vat` actual ≠ `snapshot.vat_withholding.applied`, o `VatWithholdingPolicy` da una tasa distinta de `snapshot.vat_withholding.rate_bps` → bloquea: "La condición de ReteIVA del cliente o su tarifa cambió desde que se guardó. Crea una nueva revisión."
4. Recalcula con `pricer->calculate(lines, rate)` y compara totales **clave por clave** (`gross, discount, subtotal, tax, total, cost, vat_withholding, payable`), sin depender del orden del arreglo.
5. Reglas comerciales (sin cambios).
6. `ClauseCoherence::check()`: combina las banderas y agrega `clauses` al resultado.

Una instantánea antigua de un cliente que hoy no retiene se aprueba igual que antes, salvo por E1; una de un cliente que hoy sí retiene se bloquea (su total a pagar cambiaría).

### 4.6 `CreateQuote` (nuevo flujo, una transacción)

1. Revisión: `findVisible` → `lockRevisionRoot` → `rootNumber(rootId)` → `nextRevisionNumber`.
2. `withholds = clients->lockedTaxProfile(client_id)`; `rate = policy->rateFor(withholds)`.
3. `calculation = pricer->calculate(lines, rate)`.
4. `clauses = clauseSelection->resolve(family, clause_versions, textos, validity_days)`.
5. Raíz nueva: `n = numbers->allocate(now('America/Bogota')->year)`; `quote_number = sprintf('COT-%04d-%04d', year, n)`.
6. Instantánea + `quotes.quote_number` + auditoría (`quote.created` / `quote.revised` con `quote_number`).

Una validación que falla en los pasos 2 a 4 revierte la transacción: el contador no avanza (no hay huecos por error).

### 4.7 Numeración (`QuoteNumberRepository`)

```php
interface QuoteNumberRepository
{
    /** Llamar dentro de la transacción de creación; bloquea el contador del año hasta el commit. */
    public function allocate(int $year): int;
}
```

Implementación: `insertOrIgnore(['year' => $y, 'last_number' => 0, ...])` → `where('year', $y)->lockForUpdate()->value('last_number')` → `update(last_number + 1)`.

- PostgreSQL: `INSERT … ON CONFLICT DO NOTHING` espera a la transacción que insertó la misma fila; luego `SELECT … FOR UPDATE` serializa. Sin carreras ni duplicados.
- SQLite: `lockForUpdate` se ignora, pero SQLite serializa escritores a nivel de base; el `insertOrIgnore` ya toma el bloqueo de escritura. Sirve para pruebas funcionales, no para demostrar concurrencia.
- Sin huecos por rollback. Pueden existir números de borradores nunca enviados; eso es inherente y aceptable ("sin huecos" no es requisito).
- El año es el de creación de la raíz (Bogotá). Una revisión en 2027 de `COT-2026-0123` sigue siendo `COT-2026-0123 V2`.
- Más de 9999 cotizaciones al año: `%04d` crece a 5 dígitos sin romper el formato.

### 4.8 Contratos de repositorio

```php
interface CompanyRepository
{
    /** Versión vigente sin la cuenta bancaria; agrega bank_account_configured y bank_account_summary (enmascarado). Null si no hay versión. */
    public function currentProfile(): ?array;

    /** Dentro de transacción: crea (insertOrIgnore) y bloquea la fila de la empresa; devuelve la versión vigente completa, con bank_account descifrado, solo para uso interno del caso de uso. */
    public function lockCurrentForUpdate(): ?array;

    /** Tras lockCurrentForUpdate: marca histórica la vigente e inserta la siguiente versión. Devuelve el perfil público. */
    public function appendVersion(array $attributes): array;

    public function hasAnyVersion(): bool;
}

interface ClauseRepository
{
    public function adminList(?string $family, ?string $type, bool $includeInactive): Collection;
    public function adminDetail(string $id): ?array;
    public function titleExists(string $family, string $type, string $title): bool;
    /** Dentro de transacción. Si is_default, bloquea el grupo (family, type) y limpia otras predeterminadas. */
    public function create(array $clause, array $firstVersion): array;
    /** Dentro de transacción: bloquea la cláusula y devuelve la cláusula con su versión vigente. */
    public function lockWithCurrentVersion(string $id): ?array;
    /** Tras lockWithCurrentVersion: marca histórica la vigente e inserta version + 1. */
    public function appendVersion(string $id, array $attributes): array;
    /** Dentro de transacción: cambia active/is_default respetando las reglas de predeterminada. */
    public function update(string $id, array $changes): ?array;
    /** Cláusulas activas de la familia con su versión vigente, para el editor. */
    public function currentForFamily(string $family): Collection;
    /** Bloqueo compartido cláusula → versión. Claves: id de versión. */
    public function lockedVersions(array $versionIds): Collection;
    /** Filas {body_hash, family, type, title} de versiones (cualquier estado) cuyo hash está en la lista. */
    public function hashMatches(array $hashes): Collection;
}
```

Ampliaciones:

- `QuoteRepository::rootNumber(string $rootId): ?string`; `visiblePage` y `findVisible` devuelven también `quote_number`.
- `ClientRepository::lockedTaxProfile(string $clientId): ?bool` (compartido), `updateTaxProfile(string $clientId, bool $withholds): ?array` (FOR UPDATE; devuelve `{previous, withholds_vat}`); `directory()` incluye `withholds_vat`.
- `EloquentDashboardRepository`: `pending_quotes[].quote_number`.
- Bindings en `AppServiceProvider` junto a `QuoteRepository`. `RepositoryArchitectureTest` ya verifica que todo contrato esté ligado; ampliar su glob para incluir `Application/Company/*.php`.

### 4.9 Casos de uso

- `Application\Company\PublishCompanyProfile::execute(array $input, ?User $actor, string $origin = 'admin')`:
  1. Transacción → `lockCurrentForUpdate`.
  2. Combina `bank_account`: omitido = conserva el anterior; `clear_bank_account` = null; objeto = nuevo.
  3. Calcula `changed_fields` comparando en memoria.
  4. `appendVersion`.
  5. `Audit::record(actor, 'company.published', companyVersionId, {version, origin, changed_fields, reason})`, más `bank_account: 'Datos bancarios actualizados'` o `'Datos bancarios eliminados'` cuando corresponda.

  **Nunca** registra valores en la auditoría (tampoco de los campos públicos: su historial está en las versiones). Tampoco guarda un hash de la cuenta: un número de cuenta tiene poca entropía y un hash se podría revertir por fuerza bruta. El seeder reutiliza este caso de uso con `actor = null` y `origin = 'initial_load'`.
- `Application\Quotes\CreateClause`, `PublishClauseVersion` (rechaza cuerpo idéntico normalizado: 422 "El texto no cambió"), `UpdateClause` — transacción + auditoría (`clause.created`, `clause.version_published`, `clause.updated`). `CreateClause` acepta `origin` (`admin` | `initial_draft`) y un actor opcional, para que el seeder reutilice validación, hash, reglas de predeterminada y auditoría.
- El indicador fiscal es una transacción corta en el controlador + auditoría, como el resto de `ClientController`/`CatalogController`.
- `QuoteReviewController` no se refactoriza a `Application` en esta iteración (fuera de alcance); solo recibe la validación ampliada.

### 4.10 Seeder de configuración inicial

`database/seeders/InitialConfigurationSeeder.php`, ejecutable en **cualquier entorno** (a diferencia de `DemoSeeder`) con `php artisan db:seed --class=InitialConfigurationSeeder --force`. Idempotente:

- **Empresa:** si **no existe ninguna versión** (`hasAnyVersion()`), publica la v1 con `PublishCompanyProfile` (`origin = initial_load`, `reason = 'Carga inicial de datos oficiales entregados por Systek'`, usuario Sistema). Datos oficiales:
  - `legal_name`: Systek Company S.A.S.
  - `nit`: 901704107-1
  - `address`: Cra 75 # 28-21, Belén, Medellín
  - `phone`: 3045869886
  - `email`: stip@systekcompany.io
  - `website`: https://systekcompany.io
  - `signer_name`: Jhonatan Stip Gutierrez
  - `signer_title`: Gerente
  - `trade_name`: null (el PDF usa "SYSTEK")

  Si ya existe cualquier versión, no hace nada: nunca sobrescribe lo que editó el admin. **La cuenta bancaria nunca va en el seeder**: queda `null` y la configuración aparece incompleta (`missing: ["bank_account"]`) hasta que el admin la ingresa por `/empresa`. Los valores pasan por la misma validación que la API (DV del NIT incluido); si alguno falla, el seeder se detiene sin escribir.
- **Cláusulas:** para cada `(family, type, title)` del contenido, si no existe la cláusula la crea con `CreateClause` (v1, `origin = initial_draft`, `reason = 'Redacción inicial propuesta'`, auditoría `clause.created` con `{origin: 'initial_draft', note: 'Redacción inicial propuesta', family, type, version: 1}`, usuario Sistema). Si existe, **no la toca** (ni una versión nueva ni cambios de predeterminada): desde ese momento el contenido es del administrador.
- Contenido (lo redacta backend-laravel): 7 familias × {alcance base, exclusiones, pago contado, pago anticipo 50 %/50 %, pago anticipo 60 %/40 %, garantía, vigencia, observaciones} = 56 cláusulas. Vigencia: "…vigencia de {vigencia_dias} días calendario…" (el editor usa 15 días por defecto). Pautas: sin datos bancarios, sin citar normas, sin nombres de terceros; las garantías se redactan en términos de garantía del fabricante + condiciones generales. Si se incluye un plazo concreto de mano de obra, se marca como propuesta a validar (decisión 9.8). Predeterminada de pago según la decisión 9.6.
- La interfaz muestra la insignia "Redacción inicial propuesta — pendiente de validación de Systek" mientras la versión vigente tenga `origin = initial_draft`.
- `DemoSeeder` no cambia (sigue siendo solo local/testing). Las pruebas que necesiten cláusulas usan `$this->seed(InitialConfigurationSeeder::class)`.

---

## 5. Contrato de API (`/api/v1`)

Errores: 401/403/404/409 con `{message}`; 422 con `{message, errors: {campo: [..]}}`; 429 por throttle. El proxy ya sanea estos códigos.

### 5.1 Empresa emisora (solo admin, `Cache-Control: no-store, private`)

**GET `/admin/company`** → 200

```json
{"data": {
  "configured": true, "complete": false, "missing": ["bank_account"],
  "version": 1, "origin": "initial_load",
  "legal_name": "Systek Company S.A.S.", "trade_name": null, "nit": "901704107-1",
  "address": "Cra 75 # 28-21, Belén, Medellín", "phone": "3045869886",
  "email": "stip@systekcompany.io", "website": "https://systekcompany.io",
  "signer_name": "Jhonatan Stip Gutierrez", "signer_title": "Gerente",
  "bank_account_configured": false,
  "bank_account_summary": null,
  "updated_at": "2026-09-23T10:00:00-05:00", "updated_by_name": null
}}
```

Con la cuenta configurada: `"bank_account_summary": {"bank_name": "…", "account_type": "savings", "account_number_masked": "••••1234", "has_holder": true}` (últimos 4 dígitos; nunca el número completo, ni el titular en claro). Sin versión: `configured: false`, `version: null`, todos los campos `null` y `missing` con los ocho campos obligatorios.

**POST `/admin/company`** (throttle `10,1,company-publish`). Reemplazo completo de campos públicos; los omitidos quedan `null`.

```json
{"legal_name": "Systek Company S.A.S.", "trade_name": null, "nit": "901704107-1",
 "address": "…", "phone": "…", "email": "…", "website": "https://…",
 "signer_name": "…", "signer_title": "…",
 "bank_account": {"bank_name": "…", "account_type": "savings|checking",
                  "account_number": "…", "account_number_confirmation": "…", "account_holder": null},
 "clear_bank_account": false,
 "reason": "Actualización de datos de contacto"}
```

Validación:

| Campo | Reglas |
| --- | --- |
| `legal_name` | requerido, ≤200 |
| `trade_name` | ≤100 |
| `nit` | `Nit::normalize` válido (formato y DV); si no: 422 "El dígito de verificación del NIT no es válido." |
| `address` | ≤255 |
| `phone` | ≤40, `regex:/^[0-9+() -]{7,40}$/` |
| `email` | `email:rfc`, ≤255 |
| `website` | `url:https`, ≤255 |
| `signer_name`, `signer_title` | ≤150 |
| `bank_account` | opcional; si viene, todo el objeto |
| `bank_account.bank_name` | requerido, 2..100 |
| `bank_account.account_type` | `in:savings,checking` |
| `bank_account.account_number` | requerido, `regex:/^[0-9][0-9 -]{3,28}[0-9]$/`, 6 a 20 dígitos, `confirmed` |
| `bank_account.account_holder` | nullable, ≤200 |
| `clear_bank_account` | boolean, prohibido junto con `bank_account` |
| `reason` | 5..1000 |

→ 201 con el mismo formato que GET. Los mensajes de validación nunca repiten el valor ingresado. Ninguna respuesta contiene el número de cuenta completo. **No hay endpoint para revelar la cuenta** (decisión 9.7): el error de digitación se previene con la confirmación y se verifica con el resumen enmascarado.

Quoter y approver: 403 en ambas rutas.

### 5.2 Cláusulas

**GET `/clauses?family=cctv`** (admin, quoter) → 200. Sin `family` o con un valor inválido: 422.

```json
{"data": [
  {"clause_id": "uuid", "clause_version_id": "uuid", "family": "cctv", "type": "payment",
   "title": "Anticipo 50 % / 50 %", "is_default": true, "version": 1, "origin": "initial_draft",
   "body": "…"}
]}
```

Solo cláusulas activas con su versión vigente, ordenadas por tipo (orden de `ClauseType`), predeterminada primero y luego título.

**GET `/admin/clauses?family=&type=&include_inactive=1`** (admin) → 200

```json
{"data": [
  {"id": "uuid", "family": "cctv", "type": "payment", "title": "…", "is_default": true,
   "active": true, "is_demo": false, "versions_count": 2, "updated_at": "…",
   "current_version": {"id": "uuid", "version": 2, "body": "…", "origin": "admin",
                       "created_at": "…", "published_by_name": "Admin"}}
]}
```

**GET `/admin/clauses/{id}`** (admin) → 200: la cláusula + `versions: [{id, version, status, body, origin, reason, published_by_name, created_at}]` (de más reciente a más antigua). 404 si no existe.

**POST `/admin/clauses`** (admin)

```json
{"family": "cctv", "type": "warranty", "title": "Garantía estándar", "body": "…",
 "is_default": true, "reason": "Nueva cláusula aprobada por gerencia"}
```

Validación: familia en `QuoteFamily`; tipo en `ClauseType`; título 3..120, único por `(family, type)`; cuerpo requerido, con la longitud máxima del tipo, sin tokens distintos de `{vigencia_dias}` (y ese solo en `validity`), sin secuencias bancarias; `reason` 5..1000. → 201 con el elemento en el formato de la lista.

**POST `/admin/clauses/{id}/versions`** (admin) `{"body": "…", "reason": "…"}` → 201 `{"data": {"id", "clause_id", "version", "status": "current", "body", "origin": "admin", "created_at"}}`. 404 si no existe; 422 si el texto normalizado no cambió.

**PATCH `/admin/clauses/{id}`** (admin) `{"is_default"?: bool, "active"?: bool, "reason": "…"}` (al menos uno de los dos) → 200 con el elemento. Desactivar limpia la predeterminada; marcar como predeterminada una cláusula inactiva → 422. Familia, tipo y título son inmutables.

### 5.3 Clientes

**GET `/clients`**: cada cliente agrega `"withholds_vat": false`.

**PATCH `/clients/{id}/tax-profile`** (admin) `{"withholds_vat": true, "reason": "Resolución de agente retenedor verificada"}` → 200 `{"data": {"id": "uuid", "withholds_vat": true}}`. 404; 422 sin `reason` (5..1000). Auditoría `client.tax_profile_changed` `{previous, withholds_vat, reason}`. No modifica cotizaciones guardadas.

### 5.4 Cotizaciones

**POST `/quotes/preview`**: `{"lines": [...], "client_id"?: "uuid"}`. Con `client_id`, aplica el indicador del cliente.

```json
{"data": {"currency": "COP", "lines": [...],
  "totals": {"gross": "1600000.00", "discount": "0.00", "subtotal": "1600000.00", "tax": "304000.00",
             "total": "1904000.00", "vat_withholding": "45600.00", "payable": "1858400.00", "cost": "…admin/approver"},
  "vat_withholding": {"applied": true, "rate_bps": 1500, "basis": "tax_total"},
  "profit": "…admin/approver"}}
```

**POST `/quotes`** y **POST `/quotes/{id}/revisions`**

```json
{"client_id": "uuid", "site_id": "uuid", "family": "cctv",
 "lines": [{"price_version_id": "uuid", "quantity": "8", "discount_bps": 0}],
 "scope": "…", "exclusions": "…", "payment_terms": "…", "warranty": "…",
 "validity_terms": "Esta propuesta tiene una vigencia de 15 días calendario…",
 "observations": null, "validity_days": 15,
 "clause_versions": {"scope_base": "uuid", "exclusions": "uuid", "payment": "uuid",
                     "warranty": "uuid", "validity": "uuid", "observations": null}}
```

Nuevas reglas: `family` requerido (`QuoteFamily`); `validity_terms` requerido ≤1000; `observations` nullable ≤5000; `clause_versions` `sometimes|array:scope_base,exclusions,payment,warranty,validity,observations`; cada valor `nullable|uuid|exists:clause_versions,id`. La validación bajo bloqueo (§4.3) responde 422 en `clause_versions.{tipo}`. → 201 con la instantánea redactada:

```json
{"data": {"id": "uuid", "quote_number": "COT-2026-0001", "revision_number": 1, "version_label": "V1",
  "family": "cctv", "status": "draft", "…campos existentes…": "…",
  "validity_terms": "…", "observations": null,
  "clauses": [{"type": "payment", "clause_id": "uuid", "clause_version_id": "uuid", "version": 1,
               "family": "cctv", "title": "Anticipo 50 % / 50 %", "body_hash": "sha256", "modified": false}],
  "vat_withholding": {"applied": true, "rate_bps": 1500, "basis": "tax_total"},
  "totals": {"…": "…", "vat_withholding": "45600.00", "payable": "1858400.00"},
  "emission_allowed": false}}
```

**GET `/quotes`**: cada elemento agrega `quote_number` (puede ser `null` solo si se elige "solo nuevas" en la decisión 9.4), `version_label` y `payable`.

**GET `/quotes/{id}`**: instantánea normalizada (§4.1) + los campos anteriores + `version_label` + `issuer`:

```json
"issuer": {"complete": false, "missing": ["bank_account"], "legal_name": "Systek Company S.A.S.",
           "trade_name": null, "nit": "901704107-1", "address": "Cra 75 # 28-21, Belén, Medellín",
           "phone": "3045869886", "email": "stip@systekcompany.io", "website": "https://systekcompany.io"}
```

Sin firmante ni datos bancarios (tampoco el resumen enmascarado), para ningún rol. `approval_errors` y `review_flags` se calculan también cuando `can_submit` es verdadero (con `strict = false`), para que el cotizador vea los problemas antes de enviar. `blockers` agrega "Empresa emisora incompleta: faltan …. La emisión oficial seguirá bloqueada." cuando corresponda. `emission_allowed` sigue siendo siempre `false`.

**POST `/quotes/{id}/submit`** → 200 `{"data": {"status": "in_review", "flags": [...]}}`; 422 con E1–E4 (y los bloqueos existentes).

**POST `/quotes/{id}/review`**: sin cambios de forma; `validation.flags` incluye F1/F2 y `validation.clauses` la procedencia verificada; 422 con E1–E4, M1, cambio de ReteIVA.

**GET `/quotes/{id}/pdf`**: `Content-Disposition: attachment; filename="COT-2026-0001-V1-borrador.pdf"` (legado sin número: `quote-{id}-v{n}-borrador.pdf`).

### 5.5 Rutas Laravel

```php
// grupo role:admin,quoter
Route::get('clauses', [ClauseController::class, 'current']);
// grupo role:admin
Route::get('admin/company', [CompanyController::class, 'show']);
Route::post('admin/company', [CompanyController::class, 'publish'])->middleware('throttle:10,1,company-publish');
Route::get('admin/clauses', [ClauseController::class, 'index']);
Route::post('admin/clauses', [ClauseController::class, 'store']);
Route::get('admin/clauses/{id}', [ClauseController::class, 'show'])->whereUuid('id');
Route::post('admin/clauses/{id}/versions', [ClauseController::class, 'publish'])->whereUuid('id');
Route::patch('admin/clauses/{id}', [ClauseController::class, 'update'])->whereUuid('id');
Route::patch('clients/{id}/tax-profile', [ClientController::class, 'taxProfile'])->whereUuid('id');
```

`AuthNoStore` y `withExceptions()->respond()`: agregar `api/v1/admin/company`. `bootstrap/app.php`: `$exceptions->dontFlash(['bank_account', 'bank_account.account_number', 'bank_account.account_number_confirmation'])`.

### 5.6 Allowlist del proxy (la edita **solo el agente frontend**)

```ts
// GET: agregar
clauses|admin/clauses|admin/clauses/${uuid}|admin/company
// POST: agregar
admin/clauses|admin/clauses/${uuid}/versions|admin/company
// PATCH: agregar
admin/clauses/${uuid}|clients/${uuid}/tax-profile
// PDF: aceptar ambos nombres, siempre con regex estricta
const safeName = disposition.match(new RegExp(`filename="?((?:quote-${quoteId}-v[0-9]+|COT-[0-9]{4}-[0-9]{4,6}-V[0-9]+)-borrador\\.pdf)"?(?:;|$)`))?.[1] ?? `borrador-${quoteId}.pdf`
```

Se mantienen la verificación de `Origin`, el `application/json` en escrituras y el saneamiento de errores. El proxy no registra cuerpos de solicitud; no se agrega ningún log.

---

## 6. Frontend

- **`shared/types.ts`**: `Amounts` + `vat_withholding?`, `payable?`; `Calculation` + `vat_withholding?: {applied: boolean; rate_bps: number; basis: string}`; `QuoteInput` + `family`, `validity_terms`, `observations`, `clause_versions`; `Quote` + `quote_number`, `version_label`, `family`, `clauses`, `issuer`; `QuoteListItem` + `quote_number`, `version_label`, `payable`; `Client` + `withholds_vat`; tipos `ClauseOption`, `AdminClause`, `CompanyProfile`. `shared/admin.ts`: etiquetas de familia alineadas con el plan (Datos y potencia, Software y licencias, Seguridad y renovaciones, Servicios técnicos; las claves no cambian) y `clauseTypes`.
- **`/empresa` (admin)**:
  - Aviso "Configuración incompleta" con la lista `missing` en español ("Datos bancarios") y el texto "bloquea la emisión futura, no los borradores".
  - Formulario con motivo e insignia `origin` ("Carga inicial").
  - Sección **Datos bancarios** de solo escritura:
    - Estado: resumen enmascarado ("Bancolombia · Ahorros · ••••1234") o "Sin configurar".
    - Botón "Reemplazar": abre banco, tipo (Ahorros/Corriente), número, confirmación del número y titular opcional, con `autocomplete="off"`.
    - Botón "Quitar" (`clear_bank_account`).
    - Tras guardar, los campos se limpian de la memoria del componente.
  - El NIT muestra el error de DV del servidor.
- **`/clausulas` (admin)**: filtros por familia y tipo; lista con la versión vigente e insignia "Redacción inicial propuesta"; crear; publicar versión (muestra el texto vigente y exige motivo); predeterminada/activa; historial (`GET /admin/clauses/{id}`).
- **Editor (`index.vue`)**:
  - La sección 01 agrega **Familia** (obligatoria).
  - Al elegir familia: `GET /clauses?family=`. Por tipo, si el campo está vacío o sin modificar respecto a la última precarga, se rellena con la predeterminada (vigencia renderizada con `validity_days`) y se fija `clause_versions[tipo]`. Si el usuario ya editó el texto, solo se ofrece "Cargar cláusula".
  - Por tipo: selector de cláusula ("Sin cláusula (texto libre)" incluido) e indicador "Modificada respecto a vN" (comparación visual; el servidor manda).
  - Cambiar de familia pide confirmación, limpia las referencias de la familia anterior y conserva los textos modificados.
  - Nuevos campos: Vigencia (texto) y Observaciones.
  - Al cambiar `validity_days`, re-renderiza la vigencia si no fue modificada.
  - Partidas de otra familia: insignia informativa.
  - El cliente con `withholds_vat` muestra "Agente retenedor de IVA: se calculará ReteIVA"; la vista previa envía `client_id` y se recalcula al cambiar de cliente.
  - Revisión: precarga la familia y los textos; mapea cada `clause_id` a su versión vigente. Si la versión cambió, muestra "Hay una versión nueva de «…»" con "Usar texto vigente". Una instantánea sin familia exige elegirla.
  - `loadExample`: familia `cctv` y cláusulas predeterminadas.
- **`QuoteTotals.vue`**: filas "IVA", "Total", "ReteIVA (15 % sobre IVA)" (solo si `applied`) y "Total a pagar". Solo formatea (`money`).
- **Detalle (`borradores/[id].vue`)**: encabezado `COT-2026-0001 · V2` en lugar de la referencia UUID; familia; razón social desde `issuer` (con respaldo "Systek Company"); secciones Vigencia y Observaciones; procedencia de cláusulas (título, versión, "modificada"); el botón "Enviar a revisión" se deshabilita si hay `approval_errors`; se muestran las advertencias; el nombre de descarga usa `quote_number`.
- **Listas** (`borradores/index.vue`, `inicio.vue`): muestran el número y la versión.
- **`clientes.vue`**: el admin ve el interruptor "Agente retenedor de IVA" con motivo (PATCH); el cotizador ve una insignia de solo lectura.
- **`auditoria.vue`**: etiquetas para `company.published` (incluida la línea "Datos bancarios actualizados"), `clause.created`, `clause.version_published`, `clause.updated`, `client.tax_profile_changed`, `quote.revised`.
- **Rutas admin**: agregar `/empresa` y `/clausulas` en `auth.global.ts` **y** en `app.vue` (la lista está duplicada), y los enlaces de navegación.

---

## 7. Invariantes en riesgo y protección

| Invariante | Riesgo | Protección |
| --- | --- | --- |
| Dinero en centavos enteros | Desbordamiento al multiplicar el IVA total por los bps | Descomposición `q/r` (§4.2), prueba en valores máximos, `assertIsString` y valor exacto |
| Frontend solo formatea | Calcular la ReteIVA en el cliente | La vista previa trae `vat_withholding`/`payable` del servidor; `QuoteTotals` solo usa `money()` |
| Instantáneas inmutables | Aplicar la ReteIVA o las cláusulas a cotizaciones guardadas | `SnapshotCompatibility` es de solo lectura; pruebas que comparan los bytes de `snapshot` antes/después; versiones de cláusula y empresa sin update/delete |
| Recalcular antes de aprobar | Legados con claves de totales nuevas fallan por comparación estricta | Normalización + comparación clave por clave |
| Precio/cláusula vigente | Aprobar con una cláusula reemplazada | E3 con bloqueo compartido cláusula → versión |
| Orden de bloqueos | Interbloqueos nuevos | Orden global único (§3.5); el contador se toma al final; cambios de predeterminada con bloqueo ordenado por id |
| Autorización en servidor | Quoter lee la configuración de empresa | Grupo `role:admin`; `issuer` público sin firmante ni pago; pruebas 403 por rol |
| Datos bancarios fuera de logs, auditoría, código y docs | Fuga por la respuesta, la auditoría, el PDF, las excepciones o el repositorio | Cast `encrypted:array`, `$hidden`, arreglos explícitos, resumen enmascarado solo para admin, auditoría con el texto "Datos bancarios actualizados", `dontFlash`, PDF con lista blanca, sin endpoint de revelación. Pruebas con números **ficticios** generados en la prueba que los buscan en `audit_logs`, en las respuestas y en los datos de la vista del PDF. Revisión: `grep` en el diff sin números de cuenta reales |
| Proxy con allowlist mínima | Rutas de más o nombre de PDF inyectable | Regex exactas (§5.6) y nombre de PDF con patrón cerrado |
| Emisión deshabilitada | El número COT o el membrete se confunden con una emisión | Aviso BORRADOR intacto, sin banco ni firma, `emission_allowed:false` |
| Nunca inventar datos oficiales | El seeder escribe datos no entregados | El seeder solo contiene los datos entregados por el usuario (sin banco) y los valida como la API; no sobrescribe versiones existentes; prueba que `bank_account` es `null` tras el seeder |
| Migraciones reversibles | Backfill sin `down()` | `down()` elimina la columna, el índice y la tabla; prueba de rollback/re-migrate en SQLite `:memory:` |

---

## 8. Riesgos y alternativas descartadas

**Riesgos**

- **Cotizaciones existentes:** sin familia, no pueden enviarse ni aprobarse (E1) y hay que crear una nueva revisión. Es aceptable: no hay producción. La base de desarrollo tenía 0 cotizaciones en la iteración 9; verificar antes de migrar.
- **Aprobación dependiente de las cláusulas sembradas:** tras esta iteración nadie aprueba sin cláusulas de pago/garantía/vigencia. El seeder debe ejecutarse en desarrollo y en E2E (actualizar el README § Verificación).
- **APP_KEY:** perderla hace ilegible la cuenta bancaria (hay que reingresarla). La rotación usa `previous_keys`. Las copias de base contienen solo el texto cifrado.
- **E4:** se evade con cambios mínimos (control best-effort, documentado).
- **Contención del contador:** serializa la creación de raíces nuevas por año durante el resto de la transacción; despreciable para uso interno.
- **Pruebas de concurrencia:** solo son significativas en PostgreSQL (`systek_test`).
- **Contenido de las cláusulas:** es una redacción propuesta, no validada legalmente; queda marcada y no habilita emisión.

**Descartadas**

- Tabla `quote_families`: sin requisito de administración y con el costo de FKs sobre tres tablas con datos.
- `SEQUENCE` de PostgreSQL: huecos por rollback, no portable a SQLite y un reinicio anual complicado.
- `MAX()+1` sobre `quotes`: carrera sin bloqueo de tabla.
- Empresa como fila única mutable + auditoría: la auditoría no puede guardar el banco y la emisión necesita una referencia inmutable.
- Guardar solo la referencia a la cláusula (sin el texto en la instantánea): el cotizador ajusta el texto y la instantánea debe ser autosuficiente.
- Bloquear el envío por falta de cláusulas: detiene el flujo por configuración que el cotizador no controla.
- ReteIVA por partida: la base legal es el IVA de la factura, acumula redondeos y no coincide con la retención que practicará el cliente.
- Tarifa de ReteIVA editable en BD desde la interfaz: son tasas legales que cambian poco y requieren contabilidad; `config` + tasa en la instantánea basta por ahora.
- Validador semántico por palabras clave: frágil; se deja para la fase de IA.
- Familia "general" de cláusulas: aplazada; el seeder cubre las siete familias.
- Datos de pago en el borrador: riesgo de fraude o envío accidental.
- Cuenta bancaria en cuatro columnas cifradas por separado: más superficie y copias parciales; un único JSON cifrado basta.
- Endpoint para revelar la cuenta al admin: amplía la superficie; la confirmación al ingresar y el resumen enmascarado cubren el riesgo de digitación.

---

## 9. Decisiones pendientes del usuario (valor provisional recomendado)

Ninguna bloquea el diseño; se implementa con el valor provisional.

1. **ReteIVA: base mínima y redondeo.** Provisional: sin umbral de base mínima en UVT, sobre el IVA total, half-up a centavos. Confirmar con contabilidad si aplican bases mínimas (compras/servicios) o redondeo al peso.
2. **Tarifa de ReteIVA.** Provisional: `config/quotes.php` (`QUOTE_VAT_WITHHOLDING_BPS=1500`), sin interfaz de administración.
3. **Nombres de claves de totales.** Provisional: conservar `tax` (IVA) y `total`, y agregar `vat_withholding` (ReteIVA) y `payable` (total a pagar), en lugar de renombrar a `iva`/`reteiva`/`total_a_pagar` (rompería instantáneas y el frontend).
4. **Numeración de existentes.** Provisional: backfill reversible por fecha de creación. Alternativa: solo nuevas (entonces una revisión de una raíz sin número debe asignar número a todo el linaje).
5. **Coherencia.** Provisional: la tabla de §4.4 (E1–E4 bloquean siempre; M1 advierte al enviar y bloquea al aprobar; F1/F2 advierten).
6. **Forma de pago predeterminada.** Provisional: anticipo 50 %/50 % en CCTV, datos y potencia, UPS y servicios técnicos; contado en equipos, software/licencias y seguridad/renovaciones.
7. **Visibilidad de la cuenta bancaria para el admin.** Provisional: solo escritura + resumen enmascarado (banco, tipo, últimos 4 dígitos) + confirmación del número al ingresar; sin endpoint de revelación. Alternativa: revelación auditada con throttle (y, más adelante, reverificación MFA).
8. **Plazos de garantía en la redacción inicial.** Provisional: garantía del fabricante + condiciones generales, sin plazos de mano de obra inventados; cualquier plazo concreto se marca "por validar".
9. **Reinicio anual de la numeración.** Provisional: por año de creación de la raíz, en hora de Bogotá.

---

## 10. Plan de trabajo

| Paso | Agente (modelo) | Contenido | Paralelo |
| --- | --- | --- | --- |
| 0 | Orquestador (Opus) | Confirmar o aceptar los provisionales de §9; fijar este contrato | — |
| 1 | dba (Sonnet) | Migraciones 1–4 con `down()`, modelos (`encrypted`/`$hidden`), backfill, índice parcial; verificar migrate/rollback en SQLite `:memory:` y `systek_test`. **No** migrar la base de desarrollo sin copia (`scripts/backup-local-db.sh`) y sin autorización | — |
| 2a | backend-laravel (Sonnet) — dinero y numeración | `QuoteCalculator`/`QuotePricer` ReteIVA, `VatWithholdingPolicy`, `config/quotes.php`, `clients.withholds_vat` + endpoint, `SnapshotCompatibility`, `QuoteNumberRepository`, `CreateQuote` (número + ReteIVA), `ApprovalValidation` (ReteIVA + comparación por clave), listas y dashboard, PDF (número, totales, nombre), pruebas | tras 1 |
| 2b | backend-laravel (Sonnet) — configuración y cláusulas | `QuoteFamily`, `ClauseType`, `ClauseText`, `CompanyProfile`, repositorios Company/Clause, casos de uso, controladores, rutas, no-store/`dontFlash`, `ClauseSelection` + `ClauseCoherence` integradas en `CreateQuote`/`ApprovalValidation` (**después de 2a**, porque tocan los mismos archivos), `issuer` en show y PDF, pruebas | 2b-configuración en paralelo con 2a; la integración en cotizaciones, después de 2a |
| 2c | backend-laravel (Sonnet) — redacción | `InitialConfigurationSeeder`: contenido de las 56 cláusulas según §4.10 + prueba de idempotencia | en paralelo con 2a/2b (depende solo del esquema y de la firma de `CreateClause`) |
| 3 | frontend (Sonnet) | Allowlist del proxy (único dueño), tipos, `/empresa`, `/clausulas`, editor, detalle, listas, clientes, `QuoteTotals`, auditoría, middleware/app.vue, specs E2E | en paralelo con 2 (contrato §5 fijado) |
| 4 | QA (Sonnet) | Pint; PHPUnit en SQLite y en PostgreSQL `systek_test` (incluida la concurrencia); typecheck; build; E2E en `systek_e2e` con `InitialConfigurationSeeder` | tras 2 y 3 |
| 5 | security-audit (Opus) | Datos de pago (respuesta, auditoría, logs, PDF, proxy), autorización por rol, regex del proxy | tras 4 |
| 6 | Final Reviewer (Opus) | Revisión crítica de dinero (ReteIVA), bloqueos e inmutabilidad | tras 5 |
| 7 | backend/frontend | README (API, seeder, ReteIVA, numeración), `docs/undecima-iteracion.md`, actualizar `invariantes.md` y `arquitectura.md` (orden de bloqueos) | con 6 |

---

## 11. Criterios de aceptación y pruebas requeridas

### Unitarias

- `QuoteCalculatorTest` (ReteIVA):
  - Ejemplo CCTV: IVA 304000.00 → ReteIVA 45600.00, a pagar 1858400.00.
  - IVA 0.17 → 0.03 (2,55 ↑); IVA 0.10 → 0.02 (1,5 ↑ half-up exacto); IVA 0.03 → 0.00; IVA 0.04 → 0.01.
  - Dos partidas con IVA 0.10 cada una → ReteIVA 0.03 sobre el total (no 0.04 por partida).
  - Descuento 100 % → 0 y a pagar 0.00; bps 0 → 0 y `payable === total`.
  - 100 partidas en los valores máximos con 1500 bps: resultado exacto en cadena, sin float.
  - bps −1, 10001 y no entero → `InvalidArgumentException`.
  - Las pruebas existentes siguen verdes sin cambios de valores.
- `ClauseTextTest`: normalización (espacios, mayúsculas), hash estable, render de `{vigencia_dias}`, rechazo de otros tokens y de secuencias bancarias.
- `CompanyProfileTest`: `missing()` sin versión, parcial y completa; `signer_title` obligatorio y `website`/`trade_name` opcionales.
- `NitTest`: `901704107` → 1; casos con r = 0 y r = 1; entradas con puntos, espacios y guión; DV incorrecto → `null`; longitudes fuera de rango.

### Feature

- **Empresa** (todas con datos ficticios generados en la prueba, nunca reales):
  - GET sin versión: `configured:false` con los 8 campos faltantes.
  - POST parcial → v2 con la anterior histórica.
  - Número de cuenta:
    - nunca aparece completo en GET/POST;
    - el resumen enmascarado muestra solo los últimos 4 dígitos;
    - la columna en BD no contiene el número en claro;
    - se conserva si se omite; `clear_bank_account` lo anula; `clear` junto con cuenta → 422;
    - sin confirmación, o con confirmación distinta → 422 sin repetir el valor en el mensaje.
  - El número y el titular no aparecen en `audit_logs.details` (sí "Datos bancarios actualizados"), ni en `issuer` de `/quotes/{id}`, ni en los datos de la vista del PDF.
  - NIT `901704107-1` válido; `901704107-2` → 422 por DV; NIT con puntos se normaliza.
  - `website` `http://…` → 422.
  - Quoter/approver → 403; `Cache-Control: no-store`; throttle → 429.
  - 422 por email o motivo inválidos.
- **Cláusulas:**
  - Crear v1 + auditoría; título duplicado → 422; longitud por tipo; familia/tipo inválidos → 422.
  - Publicar v2: la anterior pasa a histórica; texto sin cambios → 422.
  - Predeterminada única al cambiarla; desactivar limpia la predeterminada; predeterminada sobre inactiva → 422.
  - `GET /clauses` para quoter: solo activas y vigentes de la familia; approver → 403; admin endpoints → 403 para quoter/approver.
  - Publicar no altera los bytes de `snapshot` de cotizaciones existentes.
- **Seeder:**
  - Crea 56 cláusulas v1 `initial_draft` con auditoría "Redacción inicial propuesta".
  - Empresa v1 `initial_load` con los datos oficiales, `bank_account` null y `missing: ["bank_account"]`; auditada.
  - Segunda ejecución → 0 cambios.
  - No pisa una v2 del admin (ni de empresa ni de cláusulas); si ya existe una versión de empresa creada por el admin, no crea otra.
  - Funciona fuera de local/testing.
- **Selección al guardar:**
  - Referencias válidas → procedencia con `modified:false`; texto editado → `modified:true`.
  - Cláusula de otra familia, versión histórica, cláusula inactiva o tipo cruzado → 422 con la clave `clause_versions.{tipo}`.
  - Sin `family` → 422; vigencia referenciada con texto vacío → 422.
  - Revisión de un legado con familia nueva → OK.
- **Coherencia:**
  - Enviar sin pago/garantía/vigencia → 200 con `flags`; aprobar lo mismo → 422 y el estado sigue `in_review`.
  - Garantía de CCTV copiada en una cotización de datos/potencia → 422 al enviar y al aprobar; el mismo texto presente también en la familia propia → no bloquea.
  - Texto modificado y partida de otra familia → banderas en `quote_reviews.validation`.
  - Cláusula republicada tras guardar → aprobar 422.
  - Legado sin familia → enviar 422 con el mensaje de nueva revisión.
  - `GET /quotes/{id}` con `can_submit` devuelve errores y banderas.
  - Autoaprobación sigue prohibida.
- **ReteIVA:**
  - Vista previa con `client_id` retenedor muestra ReteIVA; sin `client_id` → 0.
  - Guardar registra `vat_withholding {applied:true, rate_bps:1500}` y los totales.
  - Cliente no retenedor → 0 y `payable = total`.
  - Legado (JSON crudo insertado) → `show` devuelve 0.00 y `payable = total`, con los bytes intactos.
  - Aprobar bloquea si cambió el indicador del cliente o `config('quotes.vat_withholding_bps')` después de guardar; aprueba si nada cambió.
  - PATCH `tax-profile` solo admin (403 para quoter/approver), con motivo y auditoría `previous`/`new`.
  - Tarifa inválida en config → error visible.
  - La redacción de costos del quoter no cambia.
- **Numeración:**
  - La primera y la segunda raíz → `COT-{año}-0001` y `0002`; la revisión hereda el número con `V2`.
  - Cambio de año con `travelTo`; frontera 2026-12-31 23:30 Bogotá → 2026.
  - Un guardado fallido (cláusula inválida) no consume número.
  - El índice único `(quote_number, revision_number)` rechaza duplicados.
- **Backfill** (`QuoteNumberBackfillTest`, SQLite `:memory:`): `migrate:rollback --path=…000003…`, insertar legados (dos raíces 2026 + revisión + una raíz 2025), re-migrar → números por año y orden, revisión con el número de su raíz, contador correcto; rollback → la columna y la tabla desaparecen.
- **Concurrencia** (`QuoteNumberConcurrencyTest`, solo pgsql `systek_test`; `markTestSkipped` en SQLite): una segunda conexión clonada a la misma base de prueba con `SET lock_timeout = '200ms'` no puede asignar mientras la primera retiene el contador (espera `QueryException` por timeout); tras el commit asigna el siguiente número. La conexión adicional debe pasar la guardia de `TestCase` (misma base `systek_test`).
- **PDF:**
  - Nombre `COT-…-V1-borrador.pdf`; la vista contiene el número, la versión, el membrete configurado, ReteIVA y el total a pagar.
  - Con cuenta bancaria (ficticia) y firmante configurados, **no** aparecen en los datos de la vista ni en el HTML.
  - "BORRADOR - NO VÁLIDO PARA ENVÍO" sigue presente; legado sin número → nombre antiguo.
- **Arquitectura:** `RepositoryArchitectureTest` verde, con el glob ampliado a `Application/Company`.

### E2E (`systek_e2e`)

- Admin: la configuración incompleta muestra "Datos bancarios"; ingresar una cuenta ficticia con confirmación; tras recargar solo se ve el resumen enmascarado y la configuración queda completa; publicar una versión de cláusula.
- Editor: elegir familia precarga las cláusulas, editar marca "modificada", guardar y ver `COT-…· V1` en el detalle y en la lista.
- Cliente retenedor (admin activa el indicador) → la vista previa muestra ReteIVA y total a pagar.
- Revisión → `V2` con el mismo número.
- Descarga de PDF con el nombre nuevo.
- Actualizar los specs existentes (payload con `family` y `validity_terms`, nombre del PDF).
- Móvil y escritorio sin desbordamiento.

### Terminado

Pint, PHPUnit (SQLite + `systek_test`), typecheck, build y E2E verdes; README y `docs/undecima-iteracion.md` actualizados; `invariantes.md` y `arquitectura.md` con el orden de bloqueos nuevo; aprobación de security-audit y Final Reviewer; informe honesto de lo no verificado.
