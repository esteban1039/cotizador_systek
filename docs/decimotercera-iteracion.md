# Iteración 13: seguimiento comercial de cotizaciones emitidas

**El sistema no envía nada al cliente.** El envío se hace por fuera (móvil, WhatsApp, correo propio, en persona) y aquí solo se registra.

## Qué incluye

- Tabla `quote_followups`, **solo de adición** (el modelo rechaza actualizar y borrar; no hay rutas de edición). Tipos: `sent` (con canal `whatsapp|email|in_person|other`), `response`, `accepted`, `rejected` y `note`.
- **Estado comercial derivado** del último evento que no es nota: no enviada, enviada, con respuesta, aceptada o rechazada. **No cambia `quotes.status`**: `issued` sigue siendo final.
- **Reglas** (`Domain\Quotes\FollowupPolicy`): solo cotizaciones emitidas; se puede reenviar; respuesta, aceptación y rechazo exigen un envío previo; aceptada y rechazada son finales (después solo notas); el rechazo exige nota; la fecha del evento no es futura ni anterior a la emisión.
- **Permisos:** consultan quienes ven la cotización; registran el administrador y el cotizador dueño; el aprobador solo consulta (403 al registrar, comprobado antes de validar el cuerpo); el cotizador ajeno recibe 404.
- **Notas:** máximo 1000 caracteres; se rechazan secuencias de 8 o más dígitos (misma regla que las cláusulas), lo que incluye fechas numéricas pegadas y evita guardar cuentas bancarias.
- **Auditoría** `quote.followup_recorded`: id, tipo, canal, fecha y longitud de la nota, nunca su texto.
- Bloqueo de la cotización (`FOR UPDATE`) dentro de la transacción del caso de uso.

## API

| Método | Ruta | Acción |
| --- | --- | --- |
| GET | `/quotes/{id}/followups` | Estado comercial, permiso y línea de tiempo |
| POST | `/quotes/{id}/followups` | Registrar un evento (límite 30/min) |

`GET /quotes/{id}` agrega `commercial_status` y `can_record_followup`. Ambas rutas están en la allowlist del proxy. Frontend: panel `QuoteFollowups` en el detalle de una cotización emitida.

## Verificación

Pint aprobado; PHPUnit 216 aprobadas y 2 omitidas en SQLite en memoria, 218 en PostgreSQL `systek_test` (según el agente); typecheck y build de Nuxt aprobados. Migración aplicada en desarrollo con respaldo previo verificado.

## Pendiente / no verificado

- Revisión de seguridad (Opus) del backend en curso al redactar; ver el resultado en la sesión.
- E2E no ejecutado (`followups.spec.ts` solo cubre el proxy); sin revisión visual en 1440 y 390.
- Concurrencia de dos registros simultáneos solo probada de forma secuencial.
- Sin filtro por estado comercial en el listado de cotizaciones.
- `datetime-local` usa la zona horaria del navegador; sin tolerancia de reloj en fechas futuras.
