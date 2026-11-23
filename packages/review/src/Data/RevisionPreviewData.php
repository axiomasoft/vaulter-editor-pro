<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;

/** Read-only immutable preview of a single document revision. */
#[Api(since: '1.0')]
final readonly class RevisionPreviewData
{
    public function __construct(
        public DocumentRevisionSnapshot $revision,
        public DocumentContent $content,
        public ?RevisionPinData $pin = null,
    ) {}
}
