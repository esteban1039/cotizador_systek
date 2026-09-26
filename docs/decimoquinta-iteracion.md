# Iteración 15: recuperación de contraseña por correo

Sin sesión. El correo se envía por SMTP de Amazon SES (Laravel `smtp`); en desarrollo `MAIL_MAILER=log` no envía nada.

## Flujo

1. `/login` → «¿Olvidaste tu contraseña?» → `/olvide-contrasena`: el usuario escribe su correo.
2. `POST /auth/password/forgot` responde siempre 202 con el mismo mensaje, exista o no la cuenta, esté activa o no. La búsqueda, el token y el envío ocurren **después** de responder (`defer`), para no revelar cuentas ni por contenido ni por tiempo.
3. El correo trae `FRONTEND_URL/restablecer#token=…&email=…`. Los datos van en el fragmento (`#`): no llegan al servidor web, a registros ni al `Referer`; la página los lee y limpia la URL.
4. `/restablecer`: contraseña nueva (mín. 12, confirmada) → `POST /auth/password/reset`.

## Controles

- Token de 256 bits aleatorios; en `password_reset_tokens` (tabla existente, sin migración nueva) solo se guarda su SHA-256. Un solo uso, vigencia **30 minutos**, y pedir otro enlace invalida el anterior (una fila por correo).
- **MFA:** si la cuenta tiene MFA activo, además del enlace se exige un código TOTP o de recuperación; el correo solo nunca basta. Un código inválido no consume el enlace, pero **5 códigos errados en 30 min lo invalidan** (auditoría `password.reset.mfa_failed`), para que tener acceso al buzón no permita adivinar códigos TOTP. El restablecimiento no desactiva el MFA.
- Al restablecer: se revocan todas las sesiones (tokens Sanctum), se borra el enlace y se audita `password.reset` (y `password.reset.requested` al solicitar). Cuentas inactivas no reciben enlace ni pueden usarlo.
- Límites: `forgot` 10/min por IP y 3 correos por cuenta cada 15 min (el exceso se ignora en silencio, misma respuesta); `reset` 10/min por IP y 5 intentos/min por cuenta (429).
- Cambiar la contraseña o el acceso (rol/estado) de una cuenta invalida cualquier enlace ya emitido. En producción con `MAIL_MAILER=log` no se envía nada (el driver log escribiría el enlace en `laravel.log`).
- Sin correo, token ni URL en logs: si SES falla solo se registra la clase del error.
- Proxy: `auth/password/forgot` y `auth/password/reset` en la allowlist POST, únicas rutas (con `auth/login`) que no exigen cookie de sesión ni reenvían token.

## Configuración (paso manual)

En el entorno privado del backend (plantilla en `backend/.env.production.example`): `MAIL_MAILER=smtp`, `MAIL_HOST=email-smtp.<región>.amazonaws.com`, `MAIL_PORT=587`, `MAIL_USERNAME`/`MAIL_PASSWORD` (credenciales **SMTP** de SES, no las de IAM), `MAIL_FROM_ADDRESS` (identidad verificada en SES) y `FRONTEND_URL` (dominio público del frontend, sin barra final). Mientras la cuenta de SES esté en *sandbox* solo se puede enviar a correos verificados: pide salir del sandbox antes del piloto. Sin `FRONTEND_URL` no se envía nada (se registra una advertencia).

## Pruebas

`backend/tests/Feature/PasswordResetTest.php`: respuesta idéntica para cuenta inexistente/inactiva/activa, solo se guarda el hash, un solo uso, invalidación por enlace nuevo, vencimiento (31 min), token/correo incorrectos, contraseña corta o sin confirmar, cuenta desactivada después de pedir, MFA (sin código, código malo, código bueno; el MFA sigue activo), límites por cuenta y falta de `FRONTEND_URL`.

## Riesgos aceptados (revisión de seguridad)

- Quien conozca el correo de otra persona puede agotar su cupo (3 solicitudes/15 min) o forzar 429 en `reset` con tokens basura, retrasando su recuperación; el administrador puede restablecerle el acceso. Además cada solicitud válida genera un correo por SES (máx. 12/h por cuenta).
- Con roles de MFA obligatorio aún sin enrolar, el enlace basta para tomar la cuenta (no hay segundo factor que exigir).
- El proxy confía en `cf-connecting-ip`: el servidor Nuxt no debe ser alcanzable sin pasar por Cloudflare, o los límites por IP se pueden eludir (los límites por cuenta siguen).
- Fuera de PHP-FPM (p. ej. `artisan serve`) `defer` puede hacer que la respuesta espere a SES; en producción (FPM) no.
