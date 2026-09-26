<?php

return [
    // Opt in per deployment; only known privileged roles are accepted.
    'mfa_required_roles' => array_values(array_intersect(
        ['admin', 'approver'],
        array_map('trim', explode(',', (string) env('MFA_REQUIRED_ROLES', ''))),
    )),
    // Secreto compartido con el proxy BFF (frontend). Con valor, toda petición /api debe traerlo en
    // `X-BFF-Secret` o se responde 404; y solo entonces se confía en `X-Forwarded-For` para la IP real.
    // En producción sin secreto la API responde 503 (falla cerrada en lugar de quedar expuesta).
    'bff_secret' => (string) env('BFF_SHARED_SECRET', ''),
];
