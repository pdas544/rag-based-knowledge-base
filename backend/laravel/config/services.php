<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'llm' => [
        'driver' => env('LLM_DRIVER', 'openrouter'),
        'chat_model' => env('OPENROUTER_CHAT_MODEL', 'openai/gpt-4o-mini'),
        'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'nvidia/nemotron-3-embed-1b:free'),
        'embedding_dim' => (int) env('EMBEDDING_DIM', 2048),
        'openrouter_api_key' => env('OPENROUTER_API_KEY', ''),
        'openrouter_base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'app_url' => env('OPENROUTER_APP_URL', env('APP_URL')),
        'app_title' => env('OPENROUTER_APP_TITLE', env('APP_NAME')),
        'temperature' => (float) env('LLM_TEMPERATURE', 0.2),
        // Production resilience: retry transient provider failures and fall
        // back to alternate OpenRouter models when the primary is unhealthy.
        'max_retries' => (int) env('LLM_MAX_RETRIES', 3),
        'retry_backoff_ms' => (int) env('LLM_RETRY_BACKOFF_MS', 500),
        'timeout' => (int) env('LLM_TIMEOUT', 120),
        'fallback_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OPENROUTER_FALLBACK_MODELS', '')),
        ))),
        // Open a circuit after N consecutive failures for TTL seconds.
        'circuit_threshold' => (int) env('LLM_CIRCUIT_THRESHOLD', 5),
        'circuit_ttl' => (int) env('LLM_CIRCUIT_TTL', 300),
    ],

    'clamav' => [
        // Opt-in malware scanning. Enable only when clamd/clamdscan is present.
        'enabled' => (bool) env('CLAMAV_ENABLED', false),
        'binary' => env('CLAMAV_BINARY', 'clamscan'),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 60),
    ],

    'moderation' => [
        // Optional LLM judge (any OpenRouter chat model). Empty = blocklist only.
        'model' => env('MODERATION_MODEL', ''),
        // Comma-separated extra PCRE bodies, e.g. "foo,bar\d+"
        'extra_patterns' => env('MODERATION_PATTERNS', ''),
    ],

    'qdrant' => [
        'host' => env('QDRANT_HOST', 'http://qdrant:6333'),
        'port' => env('QDRANT_PORT', '6333'),
        'collection' => env('QDRANT_COLLECTION', 'knowledge_base'),
        'api_key' => env('QDRANT_API_KEY', ''),
        'vector_size' => (int) env('EMBEDDING_DIM', 2048),
        // Cosine threshold calibrated for Nemotron (OpenAI-tuned 0.72 was too strict)
        'score_threshold' => (float) env('QDRANT_SCORE_THRESHOLD', 0.5),
    ],

];
