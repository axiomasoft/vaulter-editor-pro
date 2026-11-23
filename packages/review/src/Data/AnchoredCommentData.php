<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\EditorPro\Review\Enums\AnchorState;

/** Combined read-only view of a `v_node_comments` row and its optional `v_document_comment_anchors` record. */
#[Api(since: '1.0')]
final readonly class AnchoredCommentData
{
    public function __construct(
        public CommentSnapshot $comment,
        public ?CommentAnchorData $anchor,
        public string $etag,
    ) {}

    public function isResolved(): bool
    {
        return $this->comment->resolvedAt !== null;
    }

    public function isDetached(): bool
    {
        return $this->anchor !== null
            && in_array($this->anchor->anchorState, [AnchorState::Detached, AnchorState::Historical], true);
    }

    public function isHistorical(): bool
    {
        return $this->anchor?->anchorState === AnchorState::Historical;
    }

    public function isActive(): bool
    {
        return ! $this->isResolved() && ! $this->isDetached();
    }
}
