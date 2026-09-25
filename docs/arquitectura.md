# Arquitectura del backend

El proyecto usa el patrón repositorio para aislar la persistencia de los controladores y de las reglas de cotización.

- `app/Repositories/Contracts`: interfaces por módulo, con operaciones de negocio concretas.
- `app/Repositories/Eloquent`: implementaciones de persistencia con Eloquent y consultas de lectura agregadas. No devuelven query builders.
- `app/Application/Quotes`: casos de uso de creación y revisión; coordinan transacciones, repositorios, cálculo y auditoría.
- `app/Domain/Quotes`: cálculo monetario, validación comercial y ocultamiento de información por rol. Obtienen precios a través de contratos.
- `app/Http/Controllers`: validación HTTP, coordinación de operaciones y respuestas. Inyectan contratos por constructor.
- `app/Providers`: asociaciones entre interfaces e implementaciones mediante el contenedor de Laravel.

Las transacciones que abarcan varios repositorios y auditoría permanecen en el coordinador de la operación. Los bloqueos se ejecutan dentro de los repositorios y conservan su orden: ítem antes de precio; usuario antes de emitir o revocar tokens; cotización antes de cambiar su estado. Los métodos de lectura con bloqueo deben invocarse dentro de una transacción.

Las reglas estándar de validación de referencias de Laravel (`exists`) pueden verificar existencia desde los Form Requests; no sustituyen la validación del dominio ni las comprobaciones bajo bloqueo. Migraciones, seeders y pruebas pueden acceder directamente a la base de datos.

No se utiliza un repositorio CRUD genérico: cada contrato expresa las necesidades de su módulo. La calculadora sigue trabajando exclusivamente con centavos enteros. Las instantáneas históricas, permisos y restricciones de emisión deben conservarse durante cualquier refactorización.

La suite comprueba que los controladores y el dominio de cotizaciones no construyan consultas, y que el servicio de precios acepte un repositorio sustituible en pruebas sin acceder a la base de datos.
