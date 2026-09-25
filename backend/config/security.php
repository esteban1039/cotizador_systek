<?php

return [
    // Opt in per deployment; only known privileged roles are accepted.
    'mfa_required_roles' => array_values(array_intersect(
        ['admin', 'approver'],
        array_map('trim', explode(',', (string) env('MFA_REQUIRED_ROLES', ''))),
    )),
];
