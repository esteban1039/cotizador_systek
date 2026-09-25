# Iteración 10: política de MFA por rol

El servidor puede exigir verificación en dos pasos a administradores y aprobadores mediante `MFA_REQUIRED_ROLES=admin,approver` en la configuración privada del despliegue. El valor vacío mantiene MFA opcional. Esta entrega deja vacío el valor por defecto y no activa la política ni configura autenticadores en cuentas locales existentes.

Al exigir MFA a un rol, quien todavía no lo configuró puede iniciar sesión con contraseña para acceder únicamente a su cuenta, cambiar contraseña, cerrar sesión y completar la configuración de MFA. Todas las rutas comerciales quedan bloqueadas en el servidor con 403 y `mfa_enrollment_required`, incluso para sesiones emitidas antes de activar la política. La interfaz dirige a Mi cuenta y oculta la navegación comercial durante ese proceso.

Confirmar la configuración conserva el flujo existente: revoca todas las sesiones y muestra diez códigos de recuperación una sola vez. Después se inicia sesión con contraseña y segundo factor. Mientras el rol tenga MFA obligatorio, el servidor rechaza desactivarlo y la interfaz no ofrece esa opción. La restricción se comprueba dentro de la operación que bloquea al usuario para actualizarlo.

Los campos `mfa_required` y `mfa_enrollment_required` se calculan en las respuestas de login y cuenta; el estado de MFA incluye `required`. No se guardan como columnas ni requieren migraciones. La política se evalúa con el rol actual. Los cambios de rol conservan la revocación de sesiones existente.

Para activar la política en un despliegue, configurar el valor privado y reiniciar la API; si se usa configuración cacheada, regenerarla antes de reiniciar. Planificar primero los autenticadores y la custodia de los códigos: todavía no existe recuperación de MFA por correo ni restablecimiento administrativo. No se ha activado la obligatoriedad en desarrollo.

El entorno local mantiene API en 8000 y frontend en 3003. El entorno aislado de pruebas usa compose.e2e.yaml, API 8002 y PostgreSQL systek_e2e; las pruebas PHP se ejecutan únicamente en SQLite en memoria o systek_test.

Verificación: 102 pruebas backend y 699 aserciones aprobadas en SQLite en memoria y PostgreSQL systek_test; Pint aprobó 104 archivos. Typecheck y compilación de Nuxt aprobados. No se modificó el esquema ni los datos de desarrollo.

Regresión de navegador: 16 casos aprobados en escritorio y móvil (acceso y administración, MFA real y presentación de la política mediante respuestas simuladas). Las pruebas PHP cubren la política habilitada en servidor y el enrolamiento completo con revocación de sesiones.
