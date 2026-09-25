<?php

return [
    'enabled' => (bool) env('DRIVE_INVENTORY_ENABLED', false),
    'root_folder_id' => env('DRIVE_ROOT_FOLDER_ID', ''),
    // Plain text OAuth access token provisioned with drive.metadata.readonly or drive.readonly.
    // File must be inside storage/app/private and have mode 0600. No refresh token needed.
    'access_token_file' => env('DRIVE_ACCESS_TOKEN_FILE', ''),
];
