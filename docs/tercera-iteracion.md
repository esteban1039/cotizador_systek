# Iteración 3: acceso, administración y revisiones

## Entrega

Acceso individual con Sanctum, roles en servidor, clientes/sedes/contactos, administración del catálogo y versiones de precios, usuarios, reglas comerciales por familia y auditoría. Flujo de revisión con autoría, revisor distinto al autor, justificación y controles de vigencia/integridad.

## Decisiones

- El acceso compartido anterior se eliminó completamente.
- Cotizadores ven solo sus cotizaciones y no reciben costos internos.
- Administradores y aprobadores pueden ver propuestas previas sin autor, pero no enviarlas a revisión: requieren nuevo borrador identificado.
- Publicar precio archiva versiones previas sin cambiar sus importes.
- El servidor revisa precios y reglas al aprobar. Excepciones de margen/descuento/monto requieren justificación del revisor.
- Reglas comerciales inicialmente vacías; el administrador debe registrar las acordadas con Systek.
- Aprobación comercial interna no equivale a emisión. PDF, datos legales, MFA y controles de producción siguen pendientes.
- Nuevos usuarios y contraseñas se gestionan localmente; no se envían invitaciones ni correos.

## Coordinación

Se implementó con tres subagentes: acceso frontend, interfaces administrativas y pruebas de seguridad/datos. El agente principal integró rutas, revisión comercial, auditoría y verificaciones. Las correcciones incluyen conservación de PostgreSQL en HTTP, serialización de cambios de administradores y orden consistente de bloqueos de catálogo/precio.
