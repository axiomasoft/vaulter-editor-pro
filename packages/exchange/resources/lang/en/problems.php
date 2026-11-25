<?php

declare(strict_types=1);

return [
    'exchange_job_not_found' => [
        'title' => 'Exchange job not found',
        'detail' => 'The requested document exchange job was not found.',
    ],
    'exchange_idempotency_conflict' => [
        'title' => 'Exchange request key conflict',
        'detail' => 'A different exchange request already exists with this request_key.',
    ],
    'exchange_invalid_command' => [
        'title' => 'Invalid exchange command',
        'detail' => 'The document exchange command payload is invalid.',
    ],
    'exchange_unsafe_archive' => [
        'title' => 'Unsafe DOCX archive',
        'detail' => 'The DOCX archive failed pre-parse security verification.',
    ],
    'exchange_zip_limit_exceeded' => [
        'title' => 'DOCX archive limits exceeded',
        'detail' => 'The DOCX archive exceeded size, entry count, or compression ratio limits.',
    ],
    'exchange_profile_blocked' => [
        'title' => 'DOCX compatibility profile blocked',
        'detail' => 'The selected DOCX compatibility profile blocked this exchange operation.',
    ],
    'exchange_job_not_ready' => [
        'title' => 'Exchange job is not ready',
        'detail' => 'The exchange job has not finished preparing its artifact yet.',
    ],
    'exchange_stale_preview' => [
        'title' => 'Import preview is stale',
        'detail' => 'The document changed after this import preview was generated; regenerate preview before applying.',
    ],
    'exchange_already_applied' => [
        'title' => 'Exchange import already applied',
        'detail' => 'This import job has already been applied with a different apply_command_id.',
    ],
    'exchange_source_unavailable' => [
        'title' => 'Exchange source unavailable',
        'detail' => 'The referenced Media blob or revision source is missing or unavailable.',
    ],
    'exchange_runtime_failed' => [
        'title' => 'DOCX runtime failed',
        'detail' => 'The DOCX conversion runtime failed to process the request.',
    ],
    'exchange_lease_lost' => [
        'title' => 'Exchange worker lease lost',
        'detail' => 'The worker lease expired or was fenced before committing the exchange result.',
    ],
    'exchange_format_disabled' => [
        'title' => 'Exchange format disabled',
        'detail' => 'The requested exchange format is disabled.',
    ],
    'exchange_unsupported_document' => [
        'title' => 'Document does not support DOCX exchange',
        'detail' => 'This document type or state does not support DOCX exchange.',
    ],
];
