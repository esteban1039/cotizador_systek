# Iteración 6: aplicación instalable

El frontend incluye manifiesto web con identidad estable, modo standalone, inicio en `/`, iconos PNG de 192 y 512 píxeles y un icono Apple de 180 píxeles. El dibujo geométrico se genera reproduciblemente con `node frontend/scripts/generate-icons.mjs` (requiere Chromium de Playwright).

La ayuda de instalación aparece también en el acceso. En navegadores que emiten `beforeinstallprompt`, ofrece un botón para abrir la confirmación nativa; en los demás muestra instrucciones de menú. Se oculta en modo standalone o al instalar.

El service worker usa exclusivamente red: no escribe en Cache Storage ni almacena HTML autenticado, respuestas API, PDF, sesiones o cotizaciones. Ante un fallo de red en una navegación muestra una pantalla genérica embebida con respuesta 503 y `no-store`. Las rutas `/api/` no se interceptan. No hay sincronización en segundo plano ni soporte de edición offline. Mientras la página permanece abierta, el aviso de desconexión recomienda conservarla abierta y recuperar conexión antes de guardar.

El worker no fuerza activación con recarga; una actualización espera el ciclo normal del navegador. El manifiesto y el worker usan `no-cache`, y el registro verifica actualizaciones sin caché HTTP.

Para instalar desde un teléfono se necesita una URL HTTPS accesible desde ese dispositivo. Los servicios locales actuales permanecen en loopback; no se publicaron en la red. La instalación real en Safari/iOS y Android físico queda pendiente de ese entorno. Referencia: https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable

La prueba `tests/pwa.spec.ts` comprueba manifiesto e iconos, control del service worker, ayuda de instalación, aviso de desconexión, API inaccesible offline, pantalla de recuperación y Cache Storage vacío en escritorio y móvil emulado.

Verificación realizada: typecheck y build correctos; las dos pruebas PWA pasan en Chromium escritorio y móvil emulado; 65 pruebas backend (350 aserciones) pasan en SQLite y también en PostgreSQL usando `systek_test`. La regresión de navegador autenticada se interrumpió al confirmar que el acceso guardado en el archivo privado de pruebas recibe HTTP 422. Requiere actualizar las credenciales de pruebas; no se modificó la cuenta existente.
