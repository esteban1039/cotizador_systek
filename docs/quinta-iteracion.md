# Iteración 5: PDF interno de la versión guardada

El detalle permite descargar un PDF autenticado mediante `GET /api/v1/quotes/{id}/pdf`. Respeta la visibilidad del repositorio: cotizador únicamente sus cotizaciones; administrador y aprobador todas. Nuxt transmite el binario manteniendo la sesión en cookie HttpOnly y sin exponer tokens.

El documento contiene cliente, sede, versión, referencia, fechas, alcance, partidas, descuentos, impuestos, totales y condiciones guardadas. Los importes usan cadenas decimales y centavos enteros. No se recalculan precios históricos: este PDF representa la instantánea, no certifica vigencia actual.

Siempre es **BORRADOR INTERNO - NO VÁLIDO PARA ENVÍO**, incluso con aprobación comercial. Encabezado y pie identifican todas las páginas. La descarga no cambia estado, aprobación ni permisos de emisión. La versión definitiva, los datos oficiales y el archivo inmutable de documentos emitidos siguen pendientes.

La generación utiliza Dompdf con acceso remoto, ejecución PHP y JavaScript deshabilitados. Blade escapa el contenido. Un servicio de aplicación obtiene la instantánea mediante `QuoteRepository` y entrega a la plantilla solo campos permitidos; excluye costos, márgenes, usuarios y decisiones internas. Los temporales y cachés de fuentes están en almacenamiento privado; el PDF se entrega en memoria, con `no-store`, sin enlaces públicos ni persistencia automática.

Verificación: 65 pruebas backend (350 aserciones), formato aprobado, typecheck y compilación frontend aprobados, descarga real verificada en escritorio y móvil. Se inspeccionó un ejemplo A4 y un caso de 100 partidas en 14 páginas, comprobando presencia de todas las partidas, total y avisos por página. La muestra ficticia está en `output/pdf/cotizacion-demo-borrador.pdf`.
