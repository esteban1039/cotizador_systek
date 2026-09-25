> **Estado: DESCARTADA (2026-09-23).** El usuario aclaró que los archivos de Drive eran solo material de referencia y no deben importarse. El trabajo parcial se retiró. Este documento se conserva únicamente como registro de la decisión.

# Diseño — Iteración 12: importación de históricos desde Google Drive (solo lectura)

Estado: propuesta del arquitecto (2026-09-23). No implementada. Proceso: `/integration`.
Fuentes: `plan_maestro_jarvis_cotizador_systek.md` §2, §7.2, §8 (fases A–C), `docs/novena-iteracion.md`, `CLAUDE.md`, `.claude/harness/invariantes.md`, código actual de `app/Application/Drive`, `app/Infrastructure/Drive`, `HistoryController`, `EloquentHistoryRepository`, proxy Nuxt.

---

## RESUMEN

Se construye un **importador por comando artisan** que recorre en modo solo lectura la carpeta autorizada de Drive, descarga a un temporal privado los PDF, DOCX y Google Docs (exportados a texto), extrae el texto en un **proceso hijo aislado** con límites de memoria y tiempo, redacta datos bancarios, calcula **sugerencias estructuradas sin IA** (fecha, NIT, cliente por carpeta, familia, total histórico) con confianza, y registra cada archivo como **antecedente pendiente** en la bandeja existente de la iteración 9 (`historical_documents` + `historical_document_sources`). La revisión humana no cambia: aprobar exige elegir un cliente existente; nada se fusiona ni se convierte en precio vigente.

Por qué así:

- **Comando y no botón:** las colas son `sync` y la API corre con `php artisan serve` (un solo proceso). Una importación larga dentro de una petición HTTP bloquearía la API completa y excedería el timeout de 15 s del proxy. La UI solo **muestra** corridas y el libro de archivos (lectura).
- **Libro de archivos (`drive_files`)** como unidad de reanudación idempotente: cada par `(fileId, revisión)` se procesa una sola vez; los fallos se reintentan; una corrida interrumpida continúa donde quedó.
- **Transacción por archivo**, no por lote: los archivos de Drive son independientes (a diferencia del lote JSON, que es una unidad redactada por una persona). Un conflicto en un archivo no bloquea los demás.
- **Cuenta de servicio con `drive.readonly`** compartida solo en la carpeta: el acceso queda limitado a lo que se le comparte, sin pantalla de consentimiento ni refresh token de un usuario con acceso a todo su Drive.
- **Menor superficie de dependencias:** `smalot/pdfparser` (PHP puro) para PDF; lector DOCX propio de ~150 líneas con `ZipArchive` + `XMLReader` (en lugar de `phpoffice/phpword`, que exige `ext-gd`, `ext-zip` y `phpoffice/math` y trae escritura/plantillas que no usamos).

---

## IMPACTO

### Dominios
Históricos (principal), Integración Drive, Auditoría (eventos nuevos), Clientes (solo lectura para candidatos). **No se tocan** Cotizaciones, Catálogo/precios, Reglas, Identidad (salvo validar el usuario `--as`), Tablero.

### Archivos nuevos (backend)
| Capa | Archivo |
| --- | --- |
| Application (contratos) | `app/Application/Drive/DriveAccessTokenProvider.php`, `app/Application/Drive/DriveFileDownloader.php`, `app/Application/History/DocumentTextExtractor.php` |
| Application (casos de uso) | `app/Application/History/HistoricalDocumentRegistrar.php` (dedupe/alias/conflicto compartido con la importación JSON), `app/Application/History/ImportDriveHistory.php` (coordinador; dueño de transacciones y auditoría) |
| Infrastructure | `app/Infrastructure/Drive/ServiceAccountTokenProvider.php`, `app/Infrastructure/Drive/TokenFileProvider.php` (lógica actual de `token()` extraída), `app/Infrastructure/Drive/GoogleDriveFileDownloader.php`, `app/Infrastructure/Documents/IsolatedDocumentTextExtractor.php` (lanza el hijo), `app/Infrastructure/Documents/PdfTextReader.php`, `app/Infrastructure/Documents/DocxTextReader.php` |
| Domain (puro) | `app/Domain/History/HistoricalText.php` (normalizar, sanear, hash, redacción bancaria), `app/Domain/History/HistoricalTextAnalyzer.php` (sugerencias), `app/Domain/History/ColombianNit.php` (dígito de verificación DIAN), `app/Domain/History/ClientNameKey.php` (clave de nombre sin tildes, puntuación ni sufijos societarios) |
| Repositorios | `app/Repositories/Contracts/DriveImportRepository.php`, `app/Repositories/Eloquent/EloquentDriveImportRepository.php` |
| Modelos | `app/Models/DriveImportRun.php`, `app/Models/DriveFile.php` |
| Consola | `app/Console/Commands/ImportDriveHistory.php` (`systek:import-drive-history`), `app/Console/Commands/ExtractDocumentText.php` (`systek:extract-document-text`, oculto, solo lo invoca el importador) |
| HTTP | `app/Http/Controllers/DriveImportController.php` (solo lectura) |
| Provider | `app/Providers/DriveHistoryServiceProvider.php` (enlaces nuevos; evita editar `AppServiceProvider`, que la iteración 11 puede tocar) |
| Migración | `database/migrations/2026_10_12_000001_add_drive_history_import.php` |
| Pruebas | `tests/Feature/DriveHistoryImportTest.php`, `tests/Feature/DriveCredentialsTest.php`, `tests/Feature/DriveImportApiTest.php`, `tests/Feature/DocumentTextExtractionTest.php`, `tests/Unit/HistoricalTextAnalyzerTest.php` |

### Archivos modificados (backend)
- `app/Infrastructure/Drive/GoogleDriveInventoryClient.php`: usar `DriveAccessTokenProvider`; pedir además `version` y `capabilities(canDownload)`; devolver `name` de carpetas para construir la ruta. Conservar el formato del reporte del comando `systek:inventory-drive`.
- `app/Http/Controllers/HistoryController.php`: `import()` delega el bucle por registro en `HistoricalDocumentRegistrar` (mismo comportamiento; misma transacción de lote); rechazar `source_id` con prefijo `drive:`; `show()` agrega `origin`, `extraction` y candidatos por carpeta; `index()` acepta `origin`.
- `app/Repositories/Contracts/HistoryRepository.php` + `EloquentHistoryRepository.php`: `paginate(?search, ?status, ?origin)`, `saveExtraction()`, `extraction()`, `documentsInFolderWithBasename()`; `candidates()` añade coincidencia por `ClientNameKey`.
- `config/drive.php`, `.env.example`, `routes/api.php` (2 rutas GET), `bootstrap/providers.php` (1 línea), `composer.json`/`composer.lock`, `Dockerfile` (`ext-zip`).
- `tests/Feature/RepositoryArchitectureTest.php`: extender el glob a `Application/History`, `Application/Drive`, `Domain/History` (solo endurece).

### Frontend
- `frontend/server/api/backend/[...path].ts`: 2 patrones GET.
- `frontend/shared/history.ts`: tipos nuevos.
- `frontend/app/pages/historicos/index.vue`: filtro de origen, carpeta, resumen de la última corrida.
- `frontend/app/pages/historicos/[id].vue`: sección de sugerencias y datos sensibles.
- `frontend/app/pages/historicos/drive.vue` (nueva): libro de archivos de Drive, solo lectura.

### Tablas
Nuevas: `drive_import_runs`, `drive_files`, `historical_document_extractions`. Modificada: `historical_documents` (+ columna `origin`). Sin cambios: `historical_document_sources`, clientes, catálogo, precios, cotizaciones.

### Lo que NO debe cambiar
- Semántica de la importación JSON (lote ≤ 20, 1 MiB, todo o nada, hash de texto normalizado, 409 ante conflicto) y su contrato.
- Revisión humana (`POST admin/history/{id}/review`): mismas reglas, sin endpoints de edición/borrado.
- `systek:inventory-drive`: mismo propósito y salida (se añaden campos de metadatos).
- Ningún precio, ítem, cliente o cotización se crea ni modifica.

---

## DISEÑO

### 1. Flujo del comando

```
systek:import-drive-history --as=<email admin>
  0. Verificar banderas (drive.enabled && drive.import.enabled), usuario --as activo y admin,
     credencial válida. Adquirir Cache::lock('systek:drive-import') (store file). Si no, salir 1.
  1. Marcar corridas 'running' previas como 'abandoned'. Barrer temporales > 1 h en
     storage/app/private/drive-import/tmp. Crear drive_import_runs (running).
     Guardar changes.getStartPageToken en la corrida (no se consume; ver §Sincronización).
  2. Inventario: reutilizar el recorrido BFS de GoogleDriveInventoryClient (files.list por carpeta,
     trashed=false, supportsAllDrives, límites de archivos/profundidad/solicitudes, detección de
     paginación cíclica, archivos fuera de alcance descartados).
  3. Clasificar cada archivo y hacer upsert en drive_files por (drive_file_id, revision_key):
       application/pdf                                   -> pdf      revision_key = md5:<md5Checksum>
       application/vnd.openxmlformats-...document        -> docx     revision_key = md5:<md5Checksum>
       application/vnd.google-apps.document              -> gdoc     revision_key = v:<version>
       application/vnd.google-apps.folder                -> (ruta; no se registra)
       application/vnd.google-apps.shortcut              -> skipped_shortcut (NUNCA se sigue el destino)
       resto (Sheets, XLSX, DOC, imágenes, ZIP...)       -> unsupported
       size > límite                                     -> too_large (sin descargar)
       capabilities.canDownload = false                  -> not_downloadable
       binario sin md5Checksum                           -> failed (missing_checksum)
  4. Para cada fila en 'discovered' o 'failed' con attempts < 3 (orden estable por id),
     respetando --max-downloads y --max-minutes:
       a. Descargar (files.get alt=media) o exportar (files.export mimeType=text/plain) a un tempnam
          0600 dentro de drive-import/tmp (0700), en streaming y con tope de bytes.
       b. Verificar: bytes ≤ tope; md5 del archivo == md5Checksum (binarios); firma mágica
          (%PDF- / PK\x03\x04); UTF-8 válido (gdoc).
       c. Extraer texto en proceso hijo (pdf/docx) o leer texto (gdoc).
       d. HistoricalText::sanitize → redactBank → truncar a max_text_chars → normalize → hash.
          Si quedan < 40 caracteres alfanuméricos: no_text (escaneado), sin antecedente.
       e. HistoricalTextAnalyzer → sugerencias + banderas de datos sensibles.
       f. DB::transaction: lockForUpdate de la fila drive_files → HistoricalDocumentRegistrar
          (imported | duplicate | conflict) → saveExtraction (si imported) → marcar fila.
       g. finally: unlink del temporal. Pausa request_pause_ms entre descargas.
  5. Cerrar corrida: completed | partial (límite alcanzado o pendientes) | failed; contadores.
     Audit 'drive.import.finished'. Liberar lock. Imprimir resumen sin nombres de archivo.
```

**Identificador de fuente estable:** `drive:<fileId>@md5:<md5>` (PDF/DOCX) y `drive:<fileId>@v:<version>` (Google Docs). Una revisión nueva del archivo (otro md5/versión) es una fuente nueva; si el texto cambió, crea un antecedente pendiente nuevo (revisión explícita, como exige la iteración 9); si el texto es igual, queda como alias. `version` de Google Docs también sube con cambios invisibles; la deduplicación por hash absorbe ese ruido.

**Origen del antecedente:** `title` = nombre del archivo (≤ 255, saneado); `source_url` construido por el servidor a partir del ID validado (`https://drive.google.com/file/d/<id>/view` o `https://docs.google.com/document/d/<id>/edit`), nunca copiado de la respuesta; `imported_by` = usuario `--as`; `origin = 'drive'`; `client_name`, `client_nit`, `family`, `issued_on` **quedan nulos** (en la iteración 9 significan "declarado por la fuente"); las sugerencias viven en `historical_document_extractions`.

**Reglas del registrar compartido** (idénticas a la iteración 9): misma fuente + mismo hash → duplicado; misma fuente + hash distinto → conflicto (en Drive: fila `conflict`, sigue con el resto; en JSON: 409 y rollback del lote); hash existente con fuente nueva → alias en `historical_document_sources`; `UniqueConstraintViolationException` → en Drive la fila vuelve a `failed` (reintentable), en JSON el 409 actual.

### 2. Sincronización incremental (changes API): se pospone

Se **pospone** el consumo de `changes.list` a una iteración posterior. Justificación:
- El recorrido completo solo lee metadatos (~50 carpetas de cliente, cientos o pocos miles de archivos; decenas de solicitudes) y el libro evita volver a descargar pares ya procesados: una nueva corrida completa ya es incremental en descargas.
- `changes.list` entrega cambios de todo lo visible para la cuenta, sin el filtro por subárbol: exige resolver ancestros de cada cambio, archivos movidos fuera del alcance, papelera, eliminaciones y expiración del token. Es complejidad sin beneficio mientras Drive sea archivo histórico con pocos cambios (plan §7.2, recomendación).
- Costo casi nulo para no perder la opción: cada corrida guarda `start_page_token` (una llamada `changes/getStartPageToken`). La iteración futura podrá empezar desde la última corrida completa sin otro recorrido inicial.

### 3. Tipos de archivo

| Tipo | Decisión | Motivo |
| --- | --- | --- |
| PDF | Incluido | Formato principal de propuestas enviadas (plan §2). |
| DOCX | Incluido | Borradores/versiones editables (plan §2.4 "DOCX/PDF duplicados"). |
| Google Docs | **Incluido** vía `files.export` a `text/plain` | Sin parser: Google entrega texto; menor riesgo que un DOCX. Tope 2 MiB. |
| Google Sheets, XLSX | Excluido (`unsupported`) | Son el maestro de clientes/contactos (`Hoja1`, `INVITADOS SYSTEK`): pertenecen a la fase B, con datos personales y otro modelo. |
| DOC (binario), imágenes, escaneados | Excluido / `no_text` | Sin OCR en esta iteración. |
| Accesos directos | `skipped_shortcut` | El destino puede estar fuera de la carpeta autorizada. |

### 4. Base de datos

Migración única `2026_10_12_000001_add_drive_history_import.php` (prefijo `2026_10_12` reservado para la iteración 12, para no colisionar con la 11). Reversible. Antes de migrar desarrollo: `scripts/backup-local-db.sh`.

```php
// historical_documents (existente): nueva columna; filas existentes quedan 'json'
$table->string('origin', 20)->default('json')->index();   // 'json' | 'drive'

Schema::create('drive_import_runs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
    $table->string('status', 20)->index();          // running|completed|partial|failed|abandoned
    $table->string('root_folder_id', 200);
    $table->json('options');                        // límites efectivos, dry_run=false
    $table->json('counts')->nullable();             // contadores por estado
    $table->string('start_page_token', 200)->nullable();
    $table->string('error_code', 60)->nullable();   // códigos, nunca mensajes de transporte
    $table->timestamp('started_at');
    $table->timestamp('finished_at')->nullable();
});

Schema::create('drive_files', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('drive_file_id', 200);
    $table->string('revision_key', 80);             // md5:<32hex> | v:<int>
    $table->string('kind', 10);                     // pdf|docx|gdoc|other
    $table->string('name', 255);
    $table->string('mime_type', 150);
    $table->json('folder_path');                    // nombres de carpetas desde la raíz (lista)
    $table->string('parent_folder_id', 200);
    $table->unsignedBigInteger('size_bytes')->nullable();
    $table->char('md5_checksum', 32)->nullable();
    $table->timestamp('modified_time')->nullable();
    $table->string('status', 24)->index();          // ver lista abajo
    $table->string('status_code', 60)->nullable();  // detalle: md5_mismatch, bad_signature, timeout...
    $table->unsignedSmallInteger('attempts')->default(0);
    $table->string('source_id', 255)->nullable();
    $table->foreignUuid('historical_document_id')->nullable()->constrained('historical_documents')->restrictOnDelete();
    $table->foreignUuid('first_run_id')->constrained('drive_import_runs')->restrictOnDelete();
    $table->foreignUuid('last_seen_run_id')->constrained('drive_import_runs')->restrictOnDelete();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();
    $table->unique(['drive_file_id', 'revision_key']);
});

Schema::create('historical_document_extractions', function (Blueprint $table) {
    $table->foreignUuid('document_id')->primary()->constrained('historical_documents')->restrictOnDelete();
    $table->foreignUuid('drive_file_record_id')->nullable()->constrained('drive_files')->restrictOnDelete(); // FK a drive_files.id
    $table->string('extractor', 60);                // pdf:smalot/pdfparser@2.12.5 | docx:systek@1 | gdoc:export@1
    $table->string('analyzer_version', 20);
    $table->unsignedInteger('pages')->nullable();
    $table->unsignedInteger('text_chars');
    $table->boolean('text_truncated')->default(false);
    $table->json('suggestions');                    // ver contrato
    $table->json('sensitive_flags');
    $table->timestamp('created_at');
});

// down(): dropIfExists en orden inverso + dropColumn('origin') (e índice).
```

Estados de `drive_files.status`: `discovered`, `imported`, `duplicate`, `conflict`, `no_text`, `encrypted`, `unsupported`, `skipped_shortcut`, `too_large`, `not_downloadable`, `failed`. Solo `discovered` y `failed` (attempts < 3) se procesan; `--retry-failed` reinicia `attempts` de las `failed`.

Los archivos que desaparecen de Drive no se borran: su `last_seen_run_id` queda atrás y la UI puede indicarlo. No se guardan binarios originales: Drive sigue siendo el archivo histórico y `source_url` apunta a él.

Nota PostgreSQL/SQLite: `json` (no `jsonb` explícito) para mantener compatibilidad con la suite SQLite, como el resto del esquema.

### 5. Credenciales y configuración

`config/drive.php` (claves nuevas; las existentes se conservan):

```php
'credentials' => env('DRIVE_CREDENTIALS', 'token_file'),        // token_file | service_account
'service_account_file' => env('DRIVE_SERVICE_ACCOUNT_FILE', ''),// dentro de storage/app/private, 0600
'import' => [
    'enabled' => (bool) env('DRIVE_IMPORT_ENABLED', false),
    'max_download_bytes' => 20 * 1024 * 1024,      // PDF/DOCX
    'max_export_bytes' => 2 * 1024 * 1024,         // Google Docs a texto
    'max_docx_entries' => 2000,
    'max_docx_xml_bytes' => 25 * 1024 * 1024,      // word/document.xml descomprimido
    'max_docx_ratio' => 100,                       // descomprimido / comprimido
    'max_pdf_pages' => 60,
    'max_text_chars' => 100000,
    'extract_timeout_seconds' => 60,
    'extract_memory_mb' => 256,
    'request_pause_ms' => 250,
    'max_retries' => 3,
    'exclude_nits' => env('DRIVE_IMPORT_EXCLUDE_NITS', ''),  // NIT propios de Systek, no secreto
],
```

`.env.example`: `DRIVE_CREDENTIALS=token_file`, `DRIVE_SERVICE_ACCOUNT_FILE=`, `DRIVE_IMPORT_ENABLED=false`, `DRIVE_IMPORT_EXCLUDE_NITS=`.

**`ServiceAccountTokenProvider`** (sin `google/apiclient`; usa `ext-openssl`, ya presente):
- Archivo: misma política que el token actual (ruta real dentro de `storage_path('app/private')`, no enlace simbólico, modo `0600`, ≤ 16 KiB). JSON con `type=service_account`, `client_email` terminado en `.iam.gserviceaccount.com`, `private_key` PEM.
- JWT RS256 (`iss`=client_email, `scope`=`https://www.googleapis.com/auth/drive.readonly`, `aud`=`https://oauth2.googleapis.com/token`, `exp`=iat+3600) firmado con `openssl_sign`; POST a la constante `https://oauth2.googleapis.com/token`. **Se ignora `token_uri` del archivo** (evita exfiltrar la aserción a otro host).
- Token solo en memoria del proceso; se renueva cuando faltan < 5 min. Nunca en caché, disco, logs, auditoría ni excepciones (`catch (Throwable)` → código de incidencia, como el cliente actual).
- `TokenFileProvider`: la lógica actual de `GoogleDriveInventoryClient::token()`. Sirve como alternativa si la organización prohíbe claves de cuenta de servicio (token de 1 h; el comando es reanudable).

**Alcance OAuth:** `drive.readonly`. `drive.metadata.readonly` no permite `alt=media` ni `export`, así que no alcanza para descargar; `drive.file` no ve archivos existentes. El acceso efectivo lo limita el uso compartido de la carpeta con la cuenta de servicio (solo Lector).

### 6. Cliente HTTP de Drive (`GoogleDriveFileDownloader`)

- Solo `GET` a `https://www.googleapis.com/drive/v3/files/{id}?alt=media&supportsAllDrives=true` y `.../files/{id}/export?mimeType=text/plain`. El ID se valida con `^[A-Za-z0-9_-]{1,200}$`; URLs desde constantes.
- `allow_redirects=false` (el bearer no sigue un `Location`); un 3xx se registra como `failed/redirect_refused`. Verificar en la primera corrida real con `--max-downloads=5`; si Google redirigiera, la alternativa es permitir solo `*.googleusercontent.com` sin reenviar `Authorization` (decisión posterior, con revisión de seguridad).
- `connectTimeout(5)`, `timeout(120)`, `stream=true`; se escribe al temporal en bloques de 64 KiB y se aborta al superar el tope aunque `size` falte o mienta.
- 429 y 403 `rateLimitExceeded`/`userRateLimitExceeded`: backoff exponencial con `Retry-After` (máx. `max_retries`), usando `Illuminate\Support\Sleep` para poder simularlo en pruebas. 401: renovar token una vez. 403/404 restantes: `not_downloadable`/`failed`.
- Nunca se registran cuerpos de respuesta ni mensajes de excepción de transporte.

### 7. Extracción de texto

**Proceso hijo** (`IsolatedDocumentTextExtractor`): `Process::timeout(extract_timeout_seconds)->run(['php', '-d', 'memory_limit=256M', 'artisan', 'systek:extract-document-text', $kind, $path])`. El hijo:
- Rechaza rutas cuya ruta real no esté dentro de `storage/app/private/drive-import/tmp` (no sirve como lector de archivos arbitrario) y tipos distintos de `pdf|docx`.
- Imprime un único JSON `{status: ok|no_text|encrypted|failed, code, text, pages}` truncado a `max_text_chars`. El padre descarta salidas > max_text_chars × 4 bytes, JSON inválido, código de salida ≠ 0 o timeout (`failed/extract_timeout`, `failed/extract_crash`).
- Motivo: un PDF malicioso puede agotar memoria (error fatal no capturable) o entrar en bucles; en el hijo solo muere ese archivo, no la corrida.

**`PdfTextReader`** (`smalot/pdfparser`): `Config` con `setRetainImageContent(false)`, `setDecodeMemoryLimit` acotado, `setIgnoreEncryption(false)` → cifrados = `encrypted`. Texto de las primeras `max_pdf_pages` páginas (`getPages()`), `page_count` informado. Sin texto útil → `no_text`.

**`DocxTextReader`** (propio, `ZipArchive::RDONLY` + `XMLReader`):
- `numFiles` ≤ `max_docx_entries`; se lee **solo** `word/document.xml` (sin extraer a disco; ningún nombre de entrada se usa como ruta, por lo que el path traversal no aplica).
- `statName`: tamaño declarado ≤ `max_docx_xml_bytes` y relación ≤ `max_docx_ratio`; entrada cifrada → `encrypted`. Lectura por `getStream` con tope real de bytes (el tamaño declarado puede mentir).
- Rechaza cualquier `<!DOCTYPE` (XXE, "billion laughs"); `XMLReader` con `LIBXML_NONET`, sin `LIBXML_NOENT` ni `LIBXML_DTDLOAD`.
- `w:t` → texto; `w:tab` → `\t`; `w:br`/`w:cr` → `\n`; fin de `w:p` → `\n`; fin de `w:tc` → `\t`; fin de `w:tr` → `\n`. Encabezados/pies (`header*.xml`, `footer*.xml`) no se leen en esta iteración (suelen contener membrete y datos bancarios).

**Google Docs:** el texto exportado se valida como UTF-8 (`mb_check_encoding`) y pasa por el mismo saneamiento.

**`HistoricalText`** (Domain, compartido): eliminar caracteres de control salvo `\n` y `\t`, `mb_scrub`, CRLF→LF, colapsar > 2 líneas vacías, recortar; `hash = sha256` del texto final (misma función que usa la importación JSON, para que la deduplicación sea cruzada JSON↔Drive).

### 8. Datos sensibles

- **Bancarios: se redactan al ingresar** (recomendado; ver decisiones pendientes). Patrones: "cuenta (de) ahorros/corriente", nombres de bancos colombianos, "No./N°" seguidos de 6–20 dígitos con guiones o espacios → `[DATO BANCARIO REDACTADO]`. Motivo: la regla "no registrar datos bancarios"; en las propuestas son los datos de pago de Systek y no aportan al antecedente. `sensitive_flags.redacted = ['bank_account']`.
- **Personales** (correos, teléfonos, cédulas "C.C."): **se marcan, no se redactan** (son útiles para la fase B de normalización de clientes); `sensitive_flags` guarda conteos, nunca los valores. Una futura base de conocimiento deberá excluirlos (plan §8E).
- Las evidencias de sugerencias nunca incluyen fragmentos marcados como sensibles.
- El contenido se muestra escapado; nunca se interpreta como instrucciones, HTML ni código; no se envía a ningún proveedor de IA.

### 9. Extracción estructurada ligera (sin IA) — `HistoricalTextAnalyzer`

Todas son **sugerencias** con `confidence` entero 0–100 y `evidence` ≤ 120 caracteres; nunca se copian a columnas declaradas ni a catálogo/precios.

| Campo | Heurística | Confianza |
| --- | --- | --- |
| `issued_on` | Fechas `15 de marzo de 2024`, `15/03/2024`, `2024-03-15` en los primeros 1 500 caracteres; rango 2010..hoy | 80 si junto a "Fecha"/ciudad; 50 si no; 30 con `modifiedTime` de Drive como respaldo (`evidence: "drive_modified_time"`) |
| `client_nit` | `NIT[:.\s]*\d{3}\.?\d{3}\.?\d{3}(-?\d)?`; se excluyen `exclude_nits` | 90 con DV válido; 60 sin DV; descartado con DV inválido |
| `client_name` | Carpeta de nivel 1 bajo la raíz (plan §2.1: carpetas por cliente); texto tras "Señores"/"Cliente:" | 60 carpeta; 50 texto |
| `family` | Puntuación por palabras clave para `CatalogController::FAMILIES` (cámara/NVR/PoE → `cctv`; UTP/Cat6/canaleta → `data_power`; portátil/monitor → `equipment`; licencia/Office → `software`; UPS/batería → `ups`; Fortigate/firewall → `security`; mantenimiento/diagnóstico → `services`) | proporción del puntaje ganador; nulo si empate o < 40 |
| `total` | Línea con "TOTAL" seguida de valor COP (`$ 1.904.000`, `1.904.000,00`); convertido a **centavos enteros** por manipulación de cadenas (sin float); guardado como `total_cents` int | 50; siempre `historical: true` |
| `possible_duplicates` | Antecedentes Drive en la misma carpeta con el mismo nombre base (sin extensión ni sufijo ` (1)`) — enlaza DOCX↔PDF de la misma versión (plan §8C) y copias | pista, sin fusión |

`ClientNameKey`: minúsculas, sin tildes, sin puntuación, sin sufijos `S.A.S.`, `SAS`, `LTDA`, `S.A.`, espacios colapsados. Se usa para candidatos por nombre de carpeta y nombre declarado.

### 10. Relación con clientes

- `show()` calcula candidatos con: NIT/nombre declarados (como hoy) y, si faltan, NIT sugerido y nombre de carpeta. Cada candidato indica `match` (`nit`|`name`) y `source` (`declared`|`suggested`|`folder`).
- Nunca se crea, fusiona ni actualiza un cliente. La aprobación sigue exigiendo `client_id` elegido por el administrador. Las carpetas sin cliente coincidente quedan visibles para la fase B (fuera de alcance).

### 11. Contrato de consola

```
php artisan systek:import-drive-history --as=<email>
    [--max-files=2000]      1–10000 (inventario)
    [--max-depth=10]        1–30
    [--max-downloads=200]   1–5000 por corrida
    [--max-minutes=30]      1–240
    [--folder=<id>]         subcarpeta; debe pertenecer al árbol de la raíz (se comprueba en el recorrido)
    [--retry-failed]        reinicia attempts de filas 'failed'
    [--dry-run]             solo inventario y clasificación; no descarga ni escribe en la base
```

- Requiere `DRIVE_INVENTORY_ENABLED=true` **y** `DRIVE_IMPORT_ENABLED=true`. `--as`: usuario existente, activo, rol `admin`.
- Salida: solo contadores por estado, id de corrida y estado. Sin nombres de archivo, textos, tokens ni rutas del secreto.
- Código de salida: 0 `completed`; 1 `partial`, `failed`, bloqueo ocupado o configuración inválida.
- Auditoría: `drive.import.started` y `drive.import.finished` (sujeto = id de corrida; detalles: estado, contadores, límites). Los antecedentes creados se rastrean en `drive_files.historical_document_id` (no un evento por archivo).
- `systek:inventory-drive` se mantiene sin cambios de interfaz.

### 12. Contrato de API (todas bajo `role:admin`, `Cache-Control: no-store, private` por el middleware global)

**Nuevas**

`GET /api/v1/admin/history/drive/runs` → 200
```json
{ "data": [ {
  "id": "uuid", "status": "partial", "started_at": "2026-10-01T15:00:00Z", "finished_at": "2026-10-01T15:30:00Z",
  "started_by": { "id": 1, "name": "Admin" }, "root_folder_id": "1AbC...",
  "counts": { "listed": 812, "imported": 180, "duplicate": 12, "conflict": 1, "no_text": 9, "encrypted": 0,
              "unsupported": 40, "skipped_shortcut": 2, "too_large": 1, "not_downloadable": 0, "failed": 3, "discovered": 564 },
  "error_code": null } ] }
```
Últimas 20 corridas, sin paginación.

`GET /api/v1/admin/history/drive/files?status=&q=&page=` → 200 (paginado de Laravel, 50 por página)
```json
{ "data": [ {
  "id": "uuid", "drive_file_id": "1XyZ...", "name": "Propuesta CCTV.pdf", "kind": "pdf", "mime_type": "application/pdf",
  "folder_path": ["Cliente X", "2024"], "size_bytes": "348120", "modified_time": "2024-03-15T10:00:00Z",
  "status": "no_text", "status_code": null, "attempts": 1, "historical_document_id": null,
  "source_url": "https://drive.google.com/file/d/1XyZ.../view", "processed_at": "..." } ],
  "current_page": 1, "last_page": 3, "total": 120 }
```
Validación: `status` ∈ estados de `drive_files`; `q` ≤ 255 (nombre o carpeta); `page` 1–100000. 422 si no. `size_bytes` como cadena (bigint).

**Modificadas (compatibles hacia atrás, solo campos añadidos)**

`GET /api/v1/admin/history?q=&status=&origin=&page=`: `origin` ∈ `json|drive` (opcional). Cada fila añade `origin`, `client_folder_name` (nullable), `has_sensitive_data` (bool). `q` también busca en `client_folder_name`.

`GET /api/v1/admin/history/{id}`:
```json
{ "data": { "...campos actuales...": "", "origin": "drive", "sources": [ ] },
  "extraction": {
    "extractor": "pdf:smalot/pdfparser@2.12.5", "analyzer_version": "1",
    "drive_file": { "drive_file_id": "1XyZ...", "mime_type": "application/pdf", "folder_path": ["Cliente X", "2024"], "modified_time": "..." },
    "pages": 3, "text_chars": 5120, "text_truncated": false,
    "suggestions": {
      "issued_on":   { "value": "2024-03-15", "confidence": 80, "evidence": "Medellín, 15 de marzo de 2024" },
      "client_nit":  { "value": "900123456-7", "confidence": 90, "evidence": "NIT 900.123.456-7" },
      "client_name": { "value": "Cliente X", "confidence": 60, "evidence": "folder" },
      "family":      { "value": "cctv", "confidence": 70, "evidence": "cámara×12, nvr×2" },
      "total":       { "value": "1904000.00", "currency": "COP", "confidence": 50, "evidence": "TOTAL $ 1.904.000", "historical": true }
    },
    "sensitive": { "bank_account": 1, "email": 2, "phone": 3, "personal_id": 0, "redacted": ["bank_account"] },
    "possible_duplicates": [ { "id": "uuid", "title": "Propuesta CCTV.docx", "reason": "same_folder_basename" } ]
  },
  "candidates": [ { "id": "uuid", "name": "Cliente X S.A.S.", "nit": "900123456-7", "match": "nit", "source": "suggested" } ] }
```
`extraction` es `null` para antecedentes JSON. Campos de sugerencia ausentes = sin sugerencia. `total.value` es cadena decimal; el frontend solo formatea.

`POST /api/v1/admin/history/import`: `records.*.source_id` añade `not_regex:/^drive:/i` (422) para reservar el espacio de nombres del importador.

**Rutas (`routes/api.php`, grupo `role:admin`)**
```php
Route::get('admin/history/drive/runs', [DriveImportController::class, 'runs']);
Route::get('admin/history/drive/files', [DriveImportController::class, 'files']);
```
(Sin conflicto con `admin/history/{id}`: `whereUuid` y un segmento extra.)

**Proxy Nuxt** — solo el patrón GET, añadiendo al alternado existente:
```
admin/history/drive/(runs|files)
```
Sin POST/PATCH nuevos; no hay endpoint para lanzar importaciones.

### 13. Frontend

- `shared/history.ts`: `HistoryOrigin = 'json' | 'drive'`; `HistorySuggestion<T>`, `HistoryExtraction`, `DriveImportRun`, `DriveFileRow`, `DriveFileStatus` + etiquetas en español; `HistoryCandidate.source`.
- `historicos/index.vue`: filtro Origen (Todos/Importación JSON/Drive), insignia "Drive", columna Carpeta, aviso de datos sensibles; tarjeta "Última importación de Drive" (estado, fecha, contadores) con enlace a `historicos/drive`.
- `historicos/drive.vue`: tabla del libro con filtro de estado y búsqueda; etiquetas claras ("Sin texto extraíble (posible escaneo)", "Formato no admitido", "Demasiado grande", "No descargable", "Conflicto"); enlace a Drive vía `safeHistoryUrl`; enlace al antecedente cuando existe. Texto fijo: "La importación se ejecuta por comando en el servidor; esta vista es de solo lectura."
- `historicos/[id].vue`: sección "Sugerencias automáticas (sin IA)" con nivel Alta ≥ 80 / Media 50–79 / Baja < 50, leyenda "Valores históricos: no son precios vigentes ni datos confirmados"; total con `format.ts`; aviso de datos bancarios redactados y datos personales presentes; posibles duplicados; candidatos con su origen. Todo texto escapado (interpolación Vue, nunca `v-html`).
- Sin dependencias nuevas.

### 14. Dependencias exactas propuestas (decisión del usuario, ya autorizada en principio)

| Dependencia | Versión | Motivo | Notas |
| --- | --- | --- | --- |
| `smalot/pdfparser` | `^2.12.5` (lock 2.12.5, última estable consultada en Packagist el 2026-09-23) | PDF en PHP puro; requiere `ext-zlib`, `ext-iconv`, `symfony/polyfill-mbstring` (todas presentes) | LGPL-3.0: uso como biblioteca sin modificar, compatible con uso interno. Compatibilidad declarada `php >=7.1`: la prueba real con PHP 8.5 es el paso 1 del plan (ejecutar la suite y revisar deprecaciones). |
| `ext-zip` (extensión PHP) | la del paquete `libzip` de Alpine | `ZipArchive` para DOCX | `Dockerfile`: `apk add --no-cache libpq-dev libzip-dev && docker-php-ext-install pdo_pgsql zip`. Reconstruir `api` (dev y `compose.e2e.yaml`, que usan el mismo `Dockerfile`). Declarar `"ext-zip": "*"` en `composer.json`. |

Descartadas: `phpoffice/phpword` 1.4.0 (exige `ext-gd`, `ext-zip`, `phpoffice/math`; superficie de escritura/plantillas innecesaria), `google/apiclient` (enorme; el cliente HTTP de Laravel y `openssl_sign` bastan), `poppler-utils`/`pdftotext` (binario C del sistema: mejor calidad en tablas y muy probado, pero añade un paquete del SO y su superficie; queda como alternativa si la calidad de `smalot` resulta insuficiente con la muestra real), OCR (`tesseract`), fuera de alcance.

---

## INVARIANTES EN RIESGO

| Invariante | Riesgo | Protección |
| --- | --- | --- |
| Históricos nunca pasan a vigentes | El total sugerido podría leerse como precio | Solo en `historical_document_extractions.suggestions` con `historical: true`; ningún repositorio de catálogo/precios se inyecta en el importador; prueba que cuenta filas de `catalog_items`, `price_versions`, `quotes`, `clients` antes/después. |
| Dinero en centavos, cadenas decimales | Parseo de "$ 1.904.000,00" con float | Parseo por cadenas a `int`; API devuelve cadena; pruebas de formatos colombianos. |
| Sin fusión automática de clientes | Candidato por carpeta tomado como verdad | Solo candidatos con `source`; aprobación exige `client_id` explícito (sin cambios). |
| Repositorios / transacciones en coordinador | Importador con consultas directas | Todo vía `DriveImportRepository`/`HistoryRepository`; transacción por archivo en `ImportDriveHistory`; `RepositoryArchitectureTest` extendido a `Application/History`, `Application/Drive`, `Domain/History`. |
| Orden de bloqueos | Nuevo bloqueo | Orden documentado: lock de corrida (caché, fuera de la BD) → fila `drive_files` → inserción de antecedente. La revisión bloquea solo el antecedente; sin ciclo. |
| Endpoint nuevo ⇒ ruta + allowlist | Olvidar el proxy o abrir de más | Solo 2 GET exactos; sin POST; prueba de rol (401/403) y cabecera no-store. |
| Secretos | Token/clave en logs, excepciones, auditoría o reporte | Tokens en memoria; errores como códigos; `token_uri` ignorado; salida del comando sin rutas del secreto; pruebas que inspeccionan salida y auditoría. |
| Nada se escribe en Drive | Llamada de escritura accidental | Alcance `drive.readonly`; prueba que todas las solicitudes registradas son `GET` a `www.googleapis.com` salvo el `POST` al endpoint de token. |
| Pruebas sin servicios reales ni base de desarrollo | Llamar a Google o escribir en `storage/app/private` real | `Http::preventStrayRequests()`, `Http::fake`, `Process::fake`, `Sleep::fake`; `$this->app->useStoragePath(<temporal>)` en las pruebas de credenciales/temporales. |
| Migraciones reversibles, sin editar aplicadas | — | Migración nueva con `down()`; copia previa de la base. |
| Contenido no confiable | Instrucciones incrustadas, HTML | Texto escapado, nunca a IA; sin `v-html`. |

---

## RIESGOS Y ALTERNATIVAS DESCARTADAS

**Riesgos**
1. **Calidad de extracción de `smalot/pdfparser`** (tablas desordenadas, espacios): afecta sugerencias, no seguridad. Mitigación: sugerencias con confianza; muestra real limitada (`--max-downloads=20`) antes de la corrida completa; `pdftotext` como plan B.
2. **Compatibilidad con PHP 8.5** de `smalot` (deprecaciones): verificar en el paso 1; si falla, detener y consultar.
3. **Política de la organización Google** que bloquee claves de cuenta de servicio o el uso compartido externo: alternativa `token_file` (ya soportada) o excepción del administrador de Workspace.
4. **"Los lectores no pueden descargar"** activado por el dueño: `capabilities.canDownload=false` → `not_downloadable` visible en la UI.
5. **Redirecciones en `alt=media`**: rechazadas por diseño; verificar en la primera corrida real.
6. **Reglas de redacción o versión del extractor cambian**: el mismo `source_id` produciría otro hash → `conflict`. Por eso no se reprocesan filas `imported`; un futuro `--reprocess` requerirá diseño propio.
7. **Colisión con la iteración 11**: archivos compartidos `routes/api.php`, proxy Nuxt, `README.md`, `bootstrap/providers.php`, `.env.example`. Mitigación: prefijo de migración `2026_10_12_`, provider propio, cambios de 1–2 líneas en archivos compartidos; el orquestador integra secuencialmente.
8. **Tamaño de `source_text`**: la API JSON limita a 20 000 caracteres; Drive permite hasta 100 000 (columna `text`). El detalle devuelve más texto; la UI ya lo muestra escapado.
9. **Ejecución concurrente con importación JSON**: carrera en índices únicos → `failed` reintentable en Drive, 409 actual en JSON.

**Descartadas**
- **Botón "Importar ahora"** en la UI: bloquearía `php artisan serve` y excede el proxy. Se reconsiderará si se habilita la cola `database` con un worker (la tabla `jobs` existe) — decisión futura.
- **OAuth de usuario con refresh token**: `drive.readonly` sobre todo el Drive del usuario, secreto de larga vida y pantalla de consentimiento (alcance restringido).
- **Transacción por lote de archivos**: un archivo defectuoso detendría toda la corrida.
- **Llenar `client_name/client_nit/family/issued_on` con heurísticas**: haría parecer confirmados datos inferidos.
- **Guardar binarios originales**: duplica el archivo de Drive y aumenta superficie de datos; se enlaza a la fuente.
- **Consumir `changes.list` ya**: ver §2.
- **Extracción en el mismo proceso**: un PDF malicioso tumbaría la corrida (memoria fatal/bucle).

---

## PLAN

| Paso | Agente (modelo) | Trabajo | Paralelo |
| --- | --- | --- | --- |
| 0 | Orquestador | Obtener del usuario las decisiones pendientes (abajo). Confirmar que la iteración 11 no usa el prefijo `2026_10_12_`. | — |
| 1 | `devops-docker` (Sonnet) | `Dockerfile` + `ext-zip`; reconstruir `api` (dev y e2e); `composer require smalot/pdfparser:^2.12.5`; `"ext-zip": "*"`; suite actual verde en PHP 8.5 sin deprecaciones nuevas. | — |
| 2 | `integrations` (Sonnet high) | Contratos `DriveAccessTokenProvider`, `DriveFileDownloader`, `DocumentTextExtractor`; `ServiceAccountTokenProvider`, `TokenFileProvider`, refactor del inventario, `GoogleDriveFileDownloader`, `PdfTextReader`, `DocxTextReader`, `IsolatedDocumentTextExtractor`, comando oculto `systek:extract-document-text`; `config/drive.php`, `.env.example`; `DriveCredentialsTest`, `DocumentTextExtractionTest`. | ‖ 3 y 4 (contratos fijados en este documento) |
| 3 | `dba-postgresql` → `backend-laravel` (Sonnet high) | Migración y modelos; `DriveImportRepository`; extensiones de `HistoryRepository`; `HistoricalText`, `HistoricalTextAnalyzer`, `ColombianNit`, `ClientNameKey`; `HistoricalDocumentRegistrar` + refactor de `HistoryController::import` (HistoryTest intacto y verde); `ImportDriveHistory` + comando; `DriveImportController`, rutas; `DriveHistoryServiceProvider`; pruebas de importación, API y arquitectura. Copia de la base antes de migrar desarrollo. | ‖ 2 y 4 |
| 4 | `frontend-nuxt` (Sonnet medium) | Proxy (2 GET), tipos, `historicos/index.vue`, `historicos/[id].vue`, `historicos/drive.vue`; typecheck y build. Playwright con respuestas simuladas para las vistas nuevas. | ‖ 2 y 3 |
| 5 | Orquestador | Integrar 2+3 (el importador consume los contratos de 2); suite completa SQLite y `systek_test`; Pint. | — |
| 6 | `security-reviewer` (Opus high) ‖ `qa` (Sonnet high) | Seguridad: credenciales, SSRF/redirecciones, zip bomb, XXE, aislamiento del hijo, temporales, logs/auditoría, proxy, datos sensibles. QA: lista de pruebas abajo, E2E en `systek_e2e`. | ‖ |
| 7 | `docs-writer` (Sonnet) | `README.md` y `docs/duodecima-iteracion.md` (incluida la guía de autorización de Drive para el usuario). | — |
| 8 | `final-reviewer-critical` (Opus high) | Revisión final; si rechaza: corregir → QA → revisión. | — |
| 9 | Usuario | Configurar credenciales (abajo), `--dry-run`, corrida limitada, revisión de muestra, corrida completa. | — |

---

## CRITERIOS DE ACEPTACIÓN Y PRUEBAS REQUERIDAS

### Criterios
1. Con banderas apagadas o credencial inválida, el comando termina en 1 sin solicitudes HTTP y sin escribir en la base.
2. Una corrida con Drive simulado crea antecedentes `pending` con `origin=drive`, `source_id` estable, `source_url` construido, extracción y sugerencias; sin cambios en clientes, catálogo, precios ni cotizaciones.
3. Repetir la corrida no crea duplicados ni vuelve a descargar; una revisión nueva del archivo crea un antecedente nuevo solo si el texto cambió.
4. Archivos escaneados, cifrados, no admitidos, demasiado grandes, accesos directos y no descargables quedan en el libro con su estado, visibles en la UI, sin antecedente.
5. Ningún temporal sobrevive a la corrida (éxito o excepción).
6. Solo `GET` a Drive y `POST` al endpoint de token; nada se escribe en Drive.
7. Datos bancarios redactados; datos personales marcados; evidencias sin valores sensibles.
8. Endpoints nuevos solo para admin, `no-store`, y presentes en la allowlist del proxy; los demás caminos siguen dando 404 en el proxy.
9. La revisión humana y la importación JSON conservan su comportamiento (HistoryTest sin cambios salvo el caso nuevo de prefijo `drive:`).

### Pruebas backend (PHPUnit, SQLite `:memory:` y `systek_test`)
**Credenciales (`DriveCredentialsTest`)** — con `useStoragePath` temporal y clave RSA generada con `openssl_pkey_new`:
1. JWT RS256 válido (verificable con la pública), `scope=drive.readonly`, POST a `oauth2.googleapis.com/token` aunque el archivo traiga otro `token_uri`.
2. Token reutilizado en memoria y renovado cerca del vencimiento.
3. Archivo rechazado: fuera de `app/private`, enlace simbólico, modo ≠ 0600, > 16 KiB, `type` distinto, `client_email` ajeno.
4. Ni la salida del comando ni `audit_logs` contienen la clave, la aserción ni el token.

**Extracción (`DocumentTextExtractionTest`)** — fixtures generados en la prueba:
5. PDF generado con dompdf ("NIT 900.123.456-7", "TOTAL $ 1.904.000") → texto extraído.
6. PDF sin texto (página vacía) → `no_text`; bytes basura con cabecera `%PDF-` → `failed` sin romper la corrida; tope de páginas respetado.
7. DOCX creado con `ZipArchive`: párrafos, tabulaciones, tablas → texto con `\n`/`\t` esperados.
8. DOCX con `<!DOCTYPE`, sin `word/document.xml`, con demasiadas entradas, con relación de compresión excesiva (límite reducido por config en la prueba) o tamaño real superior al declarado → rechazado; ningún archivo escrito fuera del temporal.
9. `IsolatedDocumentTextExtractor` con `Process::fake`: éxito, código ≠ 0, timeout, salida sobredimensionada, JSON inválido → estados correctos.
10. El comando oculto rechaza rutas fuera de `drive-import/tmp`.

**Importación (`DriveHistoryImportTest`)** — `Http::preventStrayRequests()`, `Http::fake` de listado/descarga/exportación/token, `Sleep::fake`:
11. Flujo completo PDF + DOCX + Google Doc → 3 antecedentes pendientes; conteos de catálogo/precios/clientes/cotizaciones sin cambio; auditoría `drive.import.started/finished`.
12. Idempotencia: segunda corrida sin descargas (`Http::assertSentCount`), sin filas nuevas.
13. Nueva `md5Checksum` del mismo archivo → nueva fila y nuevo antecedente; mismo texto → alias.
14. Deduplicación cruzada con un antecedente JSON del mismo texto → alias en `historical_document_sources`.
15. Mismo `source_id` con texto distinto → `conflict`; el resto de archivos se importa.
16. md5 descargado distinto → `failed/md5_mismatch`; firma mágica incorrecta → `failed/bad_signature`.
17. `size` > límite → `too_large` sin descarga; `size` ausente y flujo que excede el tope → `too_large`.
18. Sheets, XLSX, DOC, imágenes → `unsupported`; accesos directos → `skipped_shortcut`; nunca se descargan.
19. `canDownload=false` o 403 → `not_downloadable`; 429 con `Retry-After` → reintento y éxito; 3xx → `failed/redirect_refused`.
20. `--max-downloads` y `--max-minutes` (viaje en el tiempo) → corrida `partial`; la siguiente continúa; `failed` se reintenta hasta 3 veces; `--retry-failed` reinicia.
21. Lock ocupado → salida 1 sin solicitudes. Corrida `running` huérfana → `abandoned`.
22. `--as` inexistente, inactivo o no admin → salida 1. `--dry-run` no escribe en la base ni descarga.
23. Temporales eliminados tras éxito y tras excepción simulada.
24. Solo GET a `www.googleapis.com` + POST a `oauth2.googleapis.com/token` en `Http::recorded()`.
25. Unicidad simulada (`UniqueConstraintViolationException`) → fila `failed` reintentable.

**Dominio (`HistoricalTextAnalyzerTest`, unitaria)**
26. Fechas en español y numéricas; fuera de rango descartadas; respaldo con `modifiedTime`.
27. NIT con DV válido/inválido/ausente; NIT propio excluido.
28. Familias por palabras clave; empate → nulo.
29. Totales `$ 1.904.000`, `1.904.000,00`, `$1.904.000.00` → centavos enteros; nunca float (asserción de tipo `int`).
30. Redacción bancaria; correos/teléfonos/cédulas contados, no redactados; evidencias sin valores sensibles.
31. `ClientNameKey`: "CLIENTE X SAS" ≡ "Cliente X S.A.S."; `HistoricalText::hash` igual al de la importación JSON para el mismo texto.

**API (`DriveImportApiTest` + casos en `HistoryTest`)**
32. `drive/runs` y `drive/files`: 401 invitado; 403 quoter/approver; 200 admin; `Cache-Control: no-store, private`; filtros y 422.
33. `admin/history/{id}` de un antecedente Drive incluye `extraction` y candidatos con `source`; JSON → `extraction: null`.
34. `admin/history?origin=drive` filtra; campos añadidos presentes.
35. Importación JSON con `source_id` `drive:...` → 422.
36. Aprobar un antecedente Drive: exige cliente, no crea precios/ítems.
37. `RepositoryArchitectureTest` extendido verde.

### Frontend
38. `npm run typecheck` y `npm run build`.
39. Playwright (`systek_e2e`, rutas simuladas para los endpoints Drive): filtro de origen, vista del libro, detalle con sugerencias y avisos; escritorio y móvil sin desbordamiento; el contenido malicioso (`<script>`) se muestra como texto.

### Estilo
40. `vendor/bin/pint --test` verde.

---

## DECISIONES PENDIENTES DEL USUARIO

1. **Credencial:** cuenta de servicio con clave JSON (recomendado) o token de acceso manual de 1 h (`token_file`, ya soportado).
2. **Datos bancarios:** redactarlos al ingresar (recomendado) o solo marcarlos y conservar el texto íntegro.
3. **Google Docs nativos:** incluir la exportación a texto (recomendado) o limitarse a PDF/DOCX.
4. **Dependencias:** aprobar `smalot/pdfparser ^2.12.5` (LGPL-3.0) y `ext-zip` en el `Dockerfile`.
5. **Límites por defecto:** 20 MiB por archivo, 60 páginas, 100 000 caracteres, 60 s y 256 MiB por extracción.
6. **NIT propios** de Systek a excluir (`DRIVE_IMPORT_EXCLUDE_NITS`); cuando la iteración 11 cree la empresa emisora, podrán tomarse de ahí.
7. **Posponer `changes.list`** (recomendado) guardando solo el `startPageToken`.

---

## Guía para el usuario: autorizar Drive sin que el equipo lea el secreto

1. En Google Cloud Console, crear o elegir un proyecto (p. ej. `systek-jarvis`) y **habilitar Google Drive API**.
2. IAM y administración → Cuentas de servicio → **Crear** `jarvis-drive-reader`. **No** asignarle roles en el proyecto.
3. En la cuenta de servicio → Claves → Agregar clave → **JSON**. Si la organización lo bloquea (`iam.disableServiceAccountKeyCreation`), usar la alternativa `token_file` o pedir una excepción al administrador de Workspace.
4. Guardar el archivo descargado, sin abrirlo en chats ni herramientas de IA, en:
   `backend/storage/app/private/drive/service-account.json`
   y ejecutar en la terminal propia:
   `chmod 700 backend/storage/app/private/drive && chmod 600 backend/storage/app/private/drive/service-account.json`
   (Antes de cualquier commit, confirmar con `git check-ignore -v` y `git status` que el archivo está excluido de Git; el arquitecto no pudo verificarlo porque la guardia de secretos bloquea toda lectura de esa ruta. Si no lo está, el implementador debe añadir la regla de ignorado sin leer el contenido de la carpeta.)
5. En Google Drive, **compartir la carpeta raíz del histórico** con el correo de la cuenta de servicio (`jarvis-drive-reader@<proyecto>.iam.gserviceaccount.com`) como **Lector**. Si Workspace restringe compartir fuera del dominio, el administrador debe permitirlo para ese correo. Verificar que la carpeta no tenga activado "Los lectores no pueden descargar".
6. Copiar el ID de la carpeta desde su URL (`https://drive.google.com/drive/folders/<ID>`).
7. Editar **uno mismo** `backend/.env`:
   ```
   DRIVE_INVENTORY_ENABLED=true
   DRIVE_ROOT_FOLDER_ID=<ID>
   DRIVE_CREDENTIALS=service_account
   DRIVE_SERVICE_ACCOUNT_FILE=/app/storage/app/private/drive/service-account.json
   DRIVE_IMPORT_ENABLED=true
   DRIVE_IMPORT_EXCLUDE_NITS=<NIT de Systek>
   ```
   (La ruta es la del contenedor: `./backend` se monta en `/app`.) Luego `docker compose restart api`.
8. Copia de la base y migración: `scripts/backup-local-db.sh` y después `docker compose exec api php artisan migrate`.
9. Ensayo: `docker compose exec api php artisan systek:import-drive-history --as=<correo admin> --dry-run`; después `--max-downloads=20`; revisar la muestra en Históricos; luego la corrida completa (repetible hasta `completed`).
10. Al terminar la migración histórica: eliminar la clave en Cloud Console, dejar de compartir la carpeta y poner `DRIVE_IMPORT_ENABLED=false`.
