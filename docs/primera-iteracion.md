# Iteración 1: núcleo comercial

Fuente: `plan_maestro_jarvis_cotizador_systek.md`.

## Objetivo
API local Laravel con clientes/sedes, catálogo versionado y cálculo determinista de borradores CCTV. PostgreSQL conserva los datos. Los datos de demostración no constituyen precios comerciales.

## Decisiones técnicas
- COP representados mediante cadenas decimales en la API y enteros de centavos en el cálculo. Nunca float.
- Cantidades hasta tres decimales; tasas y descuentos en puntos básicos (1900 = 19 %).
- Redondeo half-up por línea: importe bruto, descuento e impuesto, en ese orden.
- El servidor obtiene precio, costo e impuesto desde la versión de catálogo; el cliente solo transmite identificadores, cantidad y descuento.
- Una versión histórica o vencida bloquea el cálculo. La fecha final es inclusiva, zona America/Bogota.
- No se implementa emisión ni envío en esta iteración. Todos los documentos permanecen en borrador.
- API de desarrollo protegida por token compartido; no sustituye usuarios, roles y MFA del piloto.

## Pendientes del negocio
Datos legales y bancarios oficiales; usuarios y roles; catálogo aprobado; impuestos y retenciones; márgenes mínimos; límites de descuentos; cláusulas y garantías.

## Siguientes entregas
1. Editor móvil Nuxt conectado a esta API.
2. Autenticación individual, roles, aprobación y versiones inmutables.
3. PDF privado, vista previa y seguimiento.
4. Importación Drive de solo lectura y asistente estructurado.
