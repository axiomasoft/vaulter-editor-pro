<?php

declare(strict_types=1);

return [
    // Supported exchange document formats.
    'formats' => ['docx'],

    // Default OOXML compatibility profile used when the caller omits profile_id.
    'default_profile' => 'discussions',

    // Allowed OOXML compatibility profiles.
    'profiles' => ['standard', 'discussions', 'legacy-discussions', 'revisions', 'strict-ooxml'],

    // Maximum byte sizes accepted on import upload/blob read and export artifact write.
    'max_import_bytes' => 50 * 1024 * 1024,
    'max_export_bytes' => 50 * 1024 * 1024,

    // Pre-parse ZIP security quotas (engineering-standards §2.8 & §3.6).
    'zip' => [
        'max_entries' => 2_000,
        'max_entry_bytes' => 50 * 1024 * 1024,
        'max_total_bytes' => 150 * 1024 * 1024,
        'max_ratio' => 100,
        'max_rels_bytes' => 256 * 1024,
    ],

    // Worker lease TTL, job/idempotency retention, and automatic queue dispatch.
    'lease_seconds' => 60,
    'job_retention_seconds' => 7 * 86_400,
    'auto_dispatch' => true,
    'queue_connection' => env('VAULTER_EXCHANGE_QUEUE_CONNECTION'),
    'queue_name' => env('VAULTER_EXCHANGE_QUEUE', 'default'),

    // Node.js runtime bridge for @vaulter/editor-pro-docx.
    'node_binary' => env('VAULTER_DOCX_NODE_BIN', 'node'),
    'cli_path' => env('VAULTER_DOCX_CLI_PATH', dirname(__DIR__, 2).'/docx/dist/cli.js'),
    'runtime_timeout_seconds' => 30,
];
