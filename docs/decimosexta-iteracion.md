# Iteración 16: PDF oficiales en Amazon S3

Los únicos archivos de cotización que se almacenan son los **PDF oficiales emitidos** (los borradores se generan al vuelo y no se guardan). Con `QUOTE_PDF_STORAGE=s3` se guardan en S3 en lugar de la base. Reemplaza la decisión D3 de [diseno-emision-oficial.md](diseno-emision-oficial.md) (PDF cifrado en la base) solo para las emisiones nuevas.

## Comportamiento

- `QUOTE_PDF_STORAGE` = `database` (por defecto: local, pruebas, E2E) o `s3`. Con `s3` la emisión sube el PDF al disco `official_pdfs` (bucket `AWS_BUCKET`, prefijo `official-quotes/`, clave `<emission_id>.pdf`) y `quote_emission_files` guarda solo `object_key` (`content` queda vacío).
- La subida ocurre dentro de la transacción de emisión: si S3 falla, se lanza excepción, se revierte todo y **no se emite** (500 genérico). Si la subida funciona pero la transacción revierte después, queda un objeto huérfano sin referencia (inofensivo; no hay `DeleteObject`, ver IAM).
- Descarga: se lee el objeto, se comprueba el `pdf_sha256` guardado en la emisión y solo entonces se entrega; si falta el objeto, S3 no responde o el hash no coincide: 500 genérico + auditoría `quote.emission_integrity_failed`. Nunca hay URLs prefirmadas ni públicas: el PDF solo sale por la API autenticada.
- **Cifrado:** ya no se cifra con `APP_KEY` (el número de cuenta del PDF es de conocimiento público, es como se recibe el pago). Protección: bucket privado, SSE-S3 (`AES256`) exigido en cada subida y verificación de SHA-256. Las demás reglas sobre datos bancarios siguen (no se registran; en la base solo el resumen enmascarado).
- **Emisiones anteriores:** los PDF ya archivados en la base se siguen sirviendo desde allí (decisión: solo los nuevos van a S3). No hay migración de los existentes.
- Migración `2026_09_27_000001`: agrega `object_key` (única) y hace `content` nulo; reversible, y `down` se niega si hay PDF que solo existen en S3.

## Configuración (paso manual)

1. Bucket S3 privado: *Block all public access*, cifrado por defecto SSE-S3 y, recomendado, versionado (o Object Lock) porque los PDF emitidos son inmutables.
2. Usuario IAM con solo `s3:PutObject` y `s3:GetObject` sobre `arn:aws:s3:::<bucket>/official-quotes/*`.
3. En el entorno privado del backend: `QUOTE_PDF_STORAGE=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`.
4. En desarrollo con datos reales: `scripts/backup-local-db.sh` y luego `php artisan migrate` (la migración no borra datos).

## Respaldo

Los PDF en S3 **no** entran en `backup-local-db.sh`: dependen del versionado/replicación del bucket. Los emitidos antes siguen en la base y sí entran en la copia.

## Pruebas

`QuoteEmissionTest`: emisión con S3 (objeto guardado, `content` vacío, descarga con hash correcto, objeto alterado o ausente → 500 + auditoría), PDF antiguos de la base servidos tras cambiar a S3, y fallo de S3 sin emisión ni filas. Verificado con `Storage::fake`; **no se probó contra un bucket real**.
