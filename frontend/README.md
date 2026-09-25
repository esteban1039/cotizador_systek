# Editor JARVIS

Nuxt 4 + Vue. Ver [instrucciones del proyecto](../README.md).

- `app/pages/index.vue`: editor y vista previa de totales.
- `app/pages/borradores/`: lista y detalle de borradores persistidos.
- `server/api/backend/[...path].ts`: conexión privada a Laravel, limitada al entorno local.
- `scripts/dev.mjs`: inicia Nuxt en localhost usando el token de desarrollo del backend.
- `tests/editor.spec.ts`: pruebas del flujo con Playwright en escritorio y móvil.

No hay precios calculados en JavaScript ni token en runtimeConfig.public. Los valores monetarios del servidor se formatean como cadenas y enteros BigInt.
