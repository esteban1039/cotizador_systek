# Iteración 7: tablero interno y pruebas independientes

`/inicio` muestra borradores, propuestas en revisión, aprobadas internamente y versiones con vigencia vencida. El editor sigue en `/`; la navegación ofrece Inicio a todos los roles. Las cifras cuentan versiones guardadas, no oportunidades únicas. El vencimiento es un indicador derivado y puede coincidir con cualquier estado: no cambia registros ni implica envío o emisión. La fecha final es inclusiva en America/Bogota.

`GET /api/v1/dashboard` requiere autenticación y cuenta activa. Cotizadores consultan solo sus propias versiones; administradores y aprobadores consultan todas, igual que en Borradores. Devuelve fecha de corte, alcance own/all, conteos y los cinco borradores/en revisión más recientes, sin totales, costos ni márgenes. Los enlaces abren el detalle con sus permisos existentes. El repositorio realiza agregados SQL y limita las instantáneas leídas para las tarjetas; la interfaz contempla carga, error recuperable y lista vacía.

El comando `systek:create-e2e-admin --file=e2e-isolated-access.json`, ejecutado en `compose.e2e.yaml`, crea una cuenta aleatoria independiente solo en local/testing. Guarda credenciales exclusivamente en un archivo nuevo 0600 dentro de `storage/app/private`, no imprime secretos y no modifica usuarios o archivos existentes. Playwright usa ese archivo o `E2E_CREDENTIALS_PATH`. Para crear otro acceso se puede usar `--file=e2e-otro.json` y configurar la ruta del helper. No debe usarse una cuenta personal para la regresión.

También se corrigió la respuesta de autenticación sin cabecera Accept JSON: las rutas API devuelven 401 JSON en lugar de intentar resolver una ruta web de acceso inexistente y devolver 500.

Los diagnósticos automáticos del navegador no conservan payloads de acceso ni snapshots con campos de contraseña. Las capturas explícitas del tablero y otros flujos ocurren después del acceso.

Siguen pendientes importación histórica revisada, asistente de IA, MFA, datos legales y cláusulas oficiales, emisión definitiva y seguimiento/envío confirmado. Este tablero solo resume el flujo interno ya disponible.

## Aislamiento y recuperación de la demostración

Se detectó y corrigió que las variables Docker prevalecían sobre PHPUnit: una ejecución anterior de RefreshDatabase vació la base local de demostración. El usuario confirmó que no contenía datos reales y autorizó recrear la semilla y el administrador. El acceso nuevo se guarda en el archivo privado habitual; las cotizaciones anteriores de demostración no se recuperaron.

Las pruebas PHP ahora requieren conexión explícita a SQLite :memory: o PostgreSQL systek_test. Una validación anterior a RefreshDatabase comprueba la configuración resuelta y rechaza destinos alternativos, conexiones read/write/direct y URLs a otras bases. Caché, sesiones y correo también se aíslan durante la suite.

Las pruebas de navegador usan `compose.e2e.yaml`, base y volúmenes independientes, API 8002 y frontend compilado 3001. El editor de desarrollo está en 3003 para evitar el puerto de otro proyecto. Sus credenciales E2E están en `e2e-isolated-access.json`; no se usan las del administrador local. La configuración de Playwright nunca reutiliza un servidor ya iniciado.

## Verificación final

- 87 pruebas backend y 418 aserciones aprobadas en SQLite en memoria; mismas cifras en PostgreSQL systek_test.
- Pint aprobado (89 archivos), typecheck y compilación frontend aprobados.
- Regresión de navegador aislada: 33 de 34 pasaron en la primera corrida; el acceso móvil encontró el límite real de intentos. Se ajustó esa prueba para respetar la espera, sin cambiar la protección de la aplicación, y se repitió el acceso en escritorio y móvil: 2 de 2 aprobadas. Los 34 casos quedaron verificados entre ambas corridas.
- Tablero inspeccionado a 1440, 390 y 320 px sin desbordamientos; también probado contra API real, incluyendo error recuperable, lista vacía y navegación al detalle.
- Login y tablero de desarrollo responden 200. Después de las suites, systek conserva 1 administrador, 1 cliente demo y 0 cotizaciones; los registros de prueba permanecen en systek_e2e.
