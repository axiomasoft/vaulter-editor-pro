<?php

declare(strict_types=1);

return [
    'exchange_job_not_found' => 'The requested document exchange job was not found.',
    'exchange_idempotency_conflict' => 'A different exchange request already exists with this request_key.',
    'exchange_invalid_command' => 'The document exchange command payload is invalid.',
    'exchange_unsafe_archive' => 'The DOCX archive failed pre-parse security verification.',
    'exchange_zip_limit_exceeded' => 'The DOCX archive exceeded size, entry count, or compression ratio limits.',
    'exchange_profile_blocked' => 'The selected DOCX compatibility profile blocked this exchange operation.',
    'exchange_job_not_ready' => 'The exchange job has not finished preparing its artifact yet.',
    'exchange_stale_preview' => 'The document changed after this import preview was generated; regenerate preview before applying.',
    'exchange_already_applied' => 'This import job has already been applied with a different apply_command_id.',
    'exchange_source_unavailable' => 'The referenced Media blob or revision source is missing or unavailable.',
    'exchange_runtime_failed' => 'The DOCX conversion runtime failed to process the request.',
    'exchange_lease_lost' => 'The worker lease expired or was fenced before committing the exchange result.',
    'exchange_format_disabled' => 'The requested exchange format is disabled.',
    'exchange_unsupported_document' => 'This document type or state does not support DOCX exchange.',
];
