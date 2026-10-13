<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Token lifetimes are per client type. Field devices may stay offline for
    | weeks, so their tokens are long-lived and are rotated on every successful
    | refresh. Web dashboard tokens are short-lived.
    |
    */

    'auth' => [
        'token_ttl_minutes' => [
            'field_device' => (int) env('AUTH_FIELD_DEVICE_TOKEN_TTL_MINUTES', 60 * 24 * 30),
            'web_dashboard' => (int) env('AUTH_WEB_DASHBOARD_TOKEN_TTL_MINUTES', 60 * 12),
        ],

        // Oldest tokens beyond this count are revoked when a new one is issued.
        'max_active_tokens_per_user' => (int) env('AUTH_MAX_ACTIVE_TOKENS_PER_USER', 5),

        'login_rate_limit' => [
            'per_account_per_minute' => (int) env('AUTH_LOGIN_ATTEMPTS_PER_ACCOUNT', 5),
            // Field teams frequently share one satellite / NAT uplink, so the
            // per-IP budget is deliberately generous.
            'per_ip_per_minute' => (int) env('AUTH_LOGIN_ATTEMPTS_PER_IP', 60),
        ],

        'api_rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Personally Identifiable Information
    |--------------------------------------------------------------------------
    |
    | national_id_hash_key keys the HMAC used to index national IDs. It must be
    | at least 32 bytes, must differ from APP_KEY, and must never be rotated
    | without re-hashing every beneficiary (see docs/architecture/database-schema.md).
    | Generate one with: php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
    |
    */

    'pii' => [
        'national_id_hash_key' => env('NATIONAL_ID_HASH_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    'media' => [
        'disk' => env('ASSESSMENT_MEDIA_DISK', 'local'),

        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],

        // Decoded size limit for one image, whether sent inline (base64) or via
        // the binary upload endpoint. Field apps should resize before sending.
        'max_image_bytes' => (int) env('SYNC_MAX_IMAGE_BYTES', 8 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Sync
    |--------------------------------------------------------------------------
    */

    'sync' => [
        // Maximum number of records (beneficiaries + assessments + images) in one batch.
        'max_items_per_batch' => (int) env('SYNC_MAX_ITEMS_PER_BATCH', 500),

        // Inline base64 images are capped separately: they inflate the request
        // by ~33% and a dropped connection forces the whole batch to be resent.
        'max_inline_images_per_batch' => (int) env('SYNC_MAX_INLINE_IMAGES_PER_BATCH', 10),
        'max_inline_image_bytes' => (int) env('SYNC_MAX_INLINE_IMAGE_BYTES', 2 * 1024 * 1024),

        // How long a batch's idempotency lock is held while it is ingested.
        'lock_seconds' => (int) env('SYNC_LOCK_SECONDS', 120),

        // Device timestamps later than server time (after skew correction) by
        // more than this are clamped to the receive time and reported as warnings.
        'future_timestamp_tolerance_seconds' => (int) env('SYNC_FUTURE_TOLERANCE_SECONDS', 300),

        'queue' => env('SYNC_QUEUE', 'sync'),
        'media_queue' => env('SYNC_MEDIA_QUEUE', 'media'),
    ],

    'horizon' => [
        'allowed_emails' => env('HORIZON_ALLOWED_EMAILS', ''),
    ],

];
