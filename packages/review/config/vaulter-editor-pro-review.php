<?php

declare(strict_types=1);

return [
    'pins' => [
        // Automatically mark v_document_revisions.keep_forever = true when pinned.
        'enforce_retention' => true,
    ],
    'anchors' => [
        // Maximum JSON encoded payload size in bytes for selector_payload.
        'max_selector_bytes' => 16384,
        // Maximum JSON nesting depth for selector_payload.
        'max_selector_depth' => 16,
        // Maximum quote text byte length stored per anchor.
        'max_quote_bytes' => 4096,
    ],
];
