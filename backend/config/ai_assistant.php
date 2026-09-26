<?php

return [
    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),
    // Solo por variable de entorno privada; nunca en código ni en logs.
    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'model' => env('AI_ASSISTANT_MODEL', 'claude-haiku-4-5-20251001'),
    'timeout' => (int) env('AI_ASSISTANT_TIMEOUT', 12),
    'connect_timeout' => (int) env('AI_ASSISTANT_CONNECT_TIMEOUT', 3),
    'max_output_tokens' => (int) env('AI_ASSISTANT_MAX_OUTPUT_TOKENS', 2048),
    'max_catalog_items' => (int) env('AI_ASSISTANT_MAX_CATALOG_ITEMS', 400),
    'max_description_chars' => (int) env('AI_ASSISTANT_MAX_DESCRIPTION_CHARS', 120),
    'max_input_chars' => (int) env('AI_ASSISTANT_MAX_INPUT_CHARS', 4000),
    'per_minute' => (int) env('AI_ASSISTANT_PER_MINUTE', 5),
    'per_day' => (int) env('AI_ASSISTANT_PER_DAY', 30),
    // La URL base y el prompt de sistema son constantes en AnthropicQuoteDraftClient a propósito.
];
