---
name: integration
description: Proceso para integraciones externas del cotizador (Google Drive, correo, asistente IA u otros servicios).
argument-hint: "<servicio externo y objetivo>"
---

# Proceso: integración externa

Pedido: $ARGUMENTS

1. **Diseño:** `architect` con preguntas concretas (flujo de datos, credenciales, límites, modos de fallo, activación por configuración). Presenta al usuario las decisiones de alcance: lectura vs escritura, scopes, datos personales, dependencia nueva.
2. **Implementación:** `integrations` (contrato en `app/Application`, cliente en `app/Infrastructure`, config + `.env.example`, pruebas con `Http::fake()`). Comandos, endpoints o persistencia adicionales: tú, o `/database-change` si hay esquema.
3. **Validación:** `security-reviewer` → `final-reviewer` con `model: opus`. Máximo 3 subagentes por ronda: si ya usaste architect + integrations, la validación de QA la haces tú con los comandos.
4. **Reglas:** nada se envía a terceros ni a clientes sin aprobación humana; la IA propone, Laravel calcula, el humano aprueba; ninguna prueba llama al servicio real; credenciales nunca en Git, logs ni contexto; desactivada por defecto.
5. **Terminado:** pruebas con fakes verdes, seguridad sin bloqueantes, revisión aprobada, pasos manuales de configuración documentados.
