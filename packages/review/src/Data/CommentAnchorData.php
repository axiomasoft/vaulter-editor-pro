<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\SelectorKind;

/** Public read-only snapshot of a generation-scoped comment range anchor (`v_document_comment_anchors`). */
#[Api(since: '1.0')]
final readonly class CommentAnchorData
{
    /** @param array<string, mixed> $selectorPayload */
    public function __construct(
        public string $id,
        public string $commentId,
        public string $documentId,
        public int $generation,
        public int $sourcePosition,
        public int $schemaVersion,
        public string $blockId,
        public SelectorKind $selectorKind,
        public array $selectorPayload,
        public string $quoteText,
        public AnchorState $anchorState,
        public int $version,
        public string $etag,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
