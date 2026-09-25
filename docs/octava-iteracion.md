# Iteración 8: autenticación en dos pasos

La cuenta puede activar MFA con una aplicación autenticadora compatible con TOTP. La activación es voluntaria en esta etapa, recomendada especialmente para administradores y aprobadores; la obligatoriedad por rol para un piloto queda pendiente. No se activa automáticamente en cuentas existentes.

El alta solicita contraseña actual, prepara una clave temporal y exige un código para confirmar la vinculación. La interfaz permite introducir la clave manualmente en el autenticador. No utiliza servicios externos para generar QR ni almacena la clave en localStorage. Los códigos de recuperación se presentan una sola vez y permiten acceder si se pierde el dispositivo; cada código solo puede usarse una vez.

Tras activar MFA, el inicio de sesión exige contraseña y código del autenticador o de recuperación. El token de sesión solo se emite después de verificar ambos factores. La desactivación exige contraseña y segundo factor. Los cambios de MFA revocan las sesiones anteriores y quedan auditados sin claves ni códigos.

La implementación comprueba caducidad del alta temporal, límites de intentos, rechazo de códigos ya utilizados, uso único de recuperación y ocultamiento de campos sensibles en las respuestas de cuenta/usuarios. El almacenamiento de claves utiliza el cifrado de Laravel; conservar APP_KEY es necesario para descifrarlas. Los códigos de recuperación se almacenan como hashes. No existe recuperación por correo ni restablecimiento administrativo de MFA en esta etapa.

Referencias de revisión: [RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238) y [OWASP MFA](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html).

## Copia de seguridad local

Antes de aplicar la migración se creó y verificó una copia de la base de desarrollo. El comando reutilizable `bash scripts/backup-local-db.sh` produce un archivo PostgreSQL en formato custom bajo `backend/storage/app/private/backups`, con permisos privados. Comprueba que pg_restore puede leer su índice; no modifica ni restaura la base y no muestra contenido del respaldo. Estos archivos pueden contener información sensible y permanecen fuera del control de versiones por las reglas de la carpeta privada.


## API y límites

- `GET /api/v1/auth/mfa`: estado y número de códigos restantes, sin claves.
- `POST /api/v1/auth/mfa/setup`: contraseña actual; prepara clave temporal durante diez minutos.
- `POST /api/v1/auth/mfa/confirm`: código TOTP; activa y entrega diez códigos, luego revoca las sesiones.
- `POST /api/v1/auth/mfa/disable`: contraseña y código TOTP o de recuperación; elimina MFA y revoca las sesiones.
- `POST /api/v1/auth/login`: acepta el campo adicional `code`. Solo solicita segundo factor después de validar la contraseña.

Se usa Google2FA 9.1 con intervalos de 30 segundos y tolerancia de un intervalo. El último intervalo aceptado se conserva bajo bloqueo de la fila del usuario; los códigos anteriores o repetidos se rechazan. La contraseña o cambios de acceso invalidan un alta pendiente. Las respuestas de autenticación, incluidas las de error, llevan `Cache-Control: no-store, private`.

La pantalla de recuperación permanece abierta hasta confirmar que se guardaron los códigos. Recargar o salir descarta la copia en memoria; no existe un endpoint para volver a obtener esos códigos. Para emitir códigos nuevos se debe desactivar MFA con los factores existentes y volver a configurarlo. El código usado para activar ya no sirve para el login inmediato: se espera el siguiente código del autenticador o se utiliza recuperación.

## Verificación

93 pruebas backend (469 aserciones) aprobadas tanto con SQLite en memoria como en PostgreSQL systek_test; formato aprobado (94 archivos). TypeScript y compilación frontend aprobados. Regresión E2E completa de 36 casos aprobada en escritorio y móvil, incluido el flujo real de MFA en la base independiente systek_e2e. La pantalla de cuenta se inspeccionó a 390 px sin desbordamiento, sin activar MFA en la cuenta local ni capturar claves. La migración de desarrollo conservó los conteos previos de usuario, cliente y cotizaciones, dejando MFA desactivado hasta el enrolamiento voluntario.
