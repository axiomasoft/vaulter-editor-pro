<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;

/** Read-only two-version payload returned for structural comparison in an isolated viewer. */
#[Api(since: '1.0')]
final readonly class RevisionCompareData
{
    public function __construct(
        public DocumentRevisionSnapshot $from,
        public DocumentRevisionSnapshot|DocumentSnapshot $to,
        public DocumentContent $fromContent,
        public DocumentContent $toContent,
        public ?RevisionPinData $fromPin = null,
        public ?RevisionPinData $toPin = null,
    ) {}
}
