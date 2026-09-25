# Iteración 4: revisiones de cotizaciones

## Comportamiento

Una cotización guardada se corrige mediante **Crear nueva revisión**. El editor recupera cliente, sede, partidas y condiciones. Guardar crea otro borrador con identificación propia y número de revisión dentro de la misma serie. El contenido y las decisiones de la versión anterior se conservan.

La revisión empieza en borrador incluso si la versión de origen ya estaba aprobada. Requiere su propia revisión comercial; la aprobación anterior no se transfiere. El autor de la nueva revisión es la persona que la guarda.

Los precios históricos no se reemplazan silenciosamente. Cuando el precio original deja de estar disponible, el usuario debe seleccionar explícitamente el precio vigente o modificar la partida. El servidor vuelve a calcular todos los importes y verifica la vigencia al guardar.

El historial enlaza las revisiones que el usuario puede consultar. Los cotizadores conservan acceso únicamente a sus propias cotizaciones; los administradores y aprobadores pueden consultar todas. Solo administradores y cotizadores crean revisiones.

## Persistencia

Cada revisión conserva una instantánea inmutable en `quotes`, con `root_quote_id`, `previous_quote_id` y `revision_number`. La raíz se bloquea para asignar números secuenciales. Las consultas y escrituras se encapsulan en el contrato de repositorio. Este historial editorial precede a las versiones de documentos emitidos (`quote_versions`) previstas en el plan maestro; todavía no representa emisión de PDF.

`POST /api/v1/quotes/{id}/revisions` recibe los mismos campos completos que la creación de una cotización. El servidor decide la raíz, el número, el autor y el estado. El cliente no puede asignarlos.

## Límites

No se modifica una versión guardada en el lugar. No hay combinación automática de cambios ni idempotencia de peticiones: guardar dos veces mediante peticiones independientes produce dos revisiones. La interfaz bloquea el botón mientras guarda y no reintenta automáticamente las escrituras.

La emisión y el envío siguen pendientes de PDF, configuración legal oficial y controles de producción.

## Verificación

- Backend: 61 pruebas y 286 aserciones aprobadas sobre PostgreSQL en base exclusiva de pruebas; formato Pint aprobado.
- Navegador: 16 escenarios de editor y revisiones aprobados entre escritorio y móvil. Cubren cambios de alcance/cantidad, navegación del historial, conservación del original y elección explícita del precio vigente.
- El editor mantiene sus controles deshabilitados hasta terminar de inicializar y precargar los datos para evitar acciones perdidas durante la hidratación.
