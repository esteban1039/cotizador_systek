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
    // Base de conocimiento (F2, docs/diseno-base-conocimiento-ia.md §4). Apagada por defecto.
    'knowledge' => [
        'enabled' => (bool) env('AI_KNOWLEDGE_ENABLED', false),
        'top_k' => (int) env('AI_KNOWLEDGE_TOP_K', 4),
        'min_score' => (float) env('AI_KNOWLEDGE_MIN_SCORE', 0.15),
        'max_precedent_chars' => (int) env('AI_KNOWLEDGE_MAX_PRECEDENT_CHARS', 1200),
        'auto_activate' => (bool) env('AI_KNOWLEDGE_AUTO_ACTIVATE', true),
        'max_ai_assisted' => (int) env('AI_KNOWLEDGE_MAX_AI_ASSISTED', 2),
    ],
    // La URL base y el prompt de sistema son constantes en AnthropicQuoteDraftClient a propósito.
];
