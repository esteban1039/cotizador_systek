---
name: feature
description: Proceso para nueva funcionalidad, modificación funcional, API nueva o cambio de frontend en el cotizador. Úsalo cuando el usuario pida agregar o cambiar comportamiento.
argument-hint: "<descripción de la funcionalidad>"
---

# Proceso: funcionalidad

Pedido: $ARGUMENTS

1. **Clasificar** según la Política de agentes de `AGENTS.md`. Si faltan decisiones de negocio (p. ej. qué rol puede hacer algo), pregúntalas antes de implementar.
   - SIMPLE: una capa, patrón existente → implementa tú y verifica.
   - MEDIUM: endpoint nuevo siguiendo patrón, o backend + frontend sin diseño nuevo → un especialista (`backend-laravel` o `frontend-nuxt`) y `qa`; la otra capa la haces tú.
   - COMPLEX: patrón nuevo, esquema nuevo con datos, varios dominios → `architect` solo con una pregunta concreta → especialista → `qa` → `final-reviewer`. Esquema: sigue `/database-change`.
   - CRITICAL (dinero, precios, aprobación, roles, MFA, proxy, secretos): `final-reviewer` con `model: opus`; `security-reviewer` si hay endpoint nuevo, roles, MFA, proxy o archivos.
2. **Endpoint nuevo:** ruta en `backend/routes/api.php` + allowlist mínima del proxy + pruebas de permisos por rol.
3. **Correcciones:** hallazgo exacto al especialista → `qa` → revisor otra vez.
4. **Terminado:** criterios demostrados, validaciones verdes con salida real, revisión aprobada (si aplicó), README/docs al día, resumen con lo no verificado.
