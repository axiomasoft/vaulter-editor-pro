<?php

declare(strict_types=1);

return [
    'review_anchor_not_found' => [
        'title' => 'Review anchor not found',
        'detail' => 'The requested comment range anchor was not found.',
    ],
    'invalid_anchor_selector' => [
        'title' => 'Invalid anchor selector',
        'detail' => 'The comment range anchor selector is invalid for the document.',
    ],
    'revision_pin_not_found' => [
        'title' => 'Revision pin not found',
        'detail' => 'The requested revision pin was not found.',
    ],
    'invalid_comment_state' => [
        'title' => 'Invalid comment state',
        'detail' => 'The comment is not in a valid state for this operation.',
    ],
    'suggestion_conflict' => [
        'title' => 'Suggestion conflict',
        'detail' => 'The suggestion conflicts with another suggestion or the current document state.',
    ],
    'unsupported_suggestion' => [
        'title' => 'Unsupported suggestion structure',
        'detail' => 'The requested suggestion structure or operation is not supported by review capabilities.',
    ],
];
