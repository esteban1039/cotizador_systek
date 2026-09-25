# Iteración 9: bandeja de antecedentes históricos

La primera etapa de importación recibe un JSON estructurado y permite revisar antecedentes antes de incorporarlos al trabajo comercial. No accede a Google Drive, no extrae documentos originales y no ejecuta análisis de IA. La conexión de lectura con Drive y la extracción de archivos permanecen pendientes.

El acceso inicial está restringido a administradores. El archivo contiene `records` con identificador de fuente, título y texto; admite cliente/NIT, familia, fecha y vínculo de origen opcionales. Los documentos son antecedentes históricos, nunca precios vigentes. Importarlos o aprobarlos no crea ni modifica clientes, partidas de catálogo, versiones de precios o cotizaciones.

El identificador de fuente debe representar una versión estable del documento. Una importación repetida con el mismo texto no crea otro antecedente. La deduplicación compara un hash del texto normalizado (saltos de línea y espacios exteriores), no el archivo binario original. Las fuentes duplicadas conservan su identificador, título y enlace de procedencia, asociados al mismo antecedente. El mismo identificador con texto distinto genera un conflicto y no sobrescribe la versión conservada. Para registrar una revisión real, el archivo debe identificar esa revisión de forma explícita.

La carga comienza con una previsualización y exige pulsar Importar. El lote se valida y se guarda en una transacción: si contiene un conflicto o una entrada inválida, no se realiza una importación parcial. La lista permite buscar y filtrar por estado; solo el detalle devuelve el texto fuente. Los vínculos de origen se limitan a HTTPS de Drive o Docs, sin descargarlos desde el servidor.

Cada antecedente empieza pendiente. La revisión humana permite aprobarlo, asociándolo explícitamente a un cliente existente, o rechazarlo con motivo. Las coincidencias de NIT o nombre son candidatas para facilitar la revisión, no fusiones automáticas. El texto de origen se conserva y la decisión queda registrada con su autor y fecha. No hay un endpoint para editar o eliminar el antecedente aprobado.

El contenido importado se presenta como texto escapado y no se interpreta como instrucciones, HTML ni código. No se envía a ningún proveedor de IA. Una futura base de conocimiento deberá separar y revisar los extractos autorizados, excluyendo datos bancarios y datos personales innecesarios.

Antes de migrar desarrollo se conserva una copia privada con `scripts/backup-local-db.sh`. Las pruebas PHP usan SQLite en memoria o systek_test; las pruebas de interfaz escriben únicamente en systek_e2e.

Verificación del backend: 99 pruebas y 555 aserciones aprobadas tanto en SQLite en memoria como en PostgreSQL `systek_test`; Pint aprobó 100 archivos. Typecheck y compilación de Nuxt aprobados. Migración local aplicada después de copia privada verificada; se conservaron 1 usuario, 1 cliente y 0 cotizaciones, sin importar históricos ficticios en desarrollo.

Navegador: 40 casos aprobados en la suite completa y 2 casos de importación aprobados al repetirlos tras corregir localizadores y sincronización de navegación del test (42 casos verificados entre ambas ejecuciones). Revisión visual de la bandeja aprobada en escritorio y móvil, sin desbordamiento horizontal.
