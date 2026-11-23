<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Support\ReviewTags;

/** JSON representation of a generation-scoped comment anchor (`v_document_comment_anchors`). */
#[Internal]
final class CommentAnchorResource extends JsonResource
{
    public function __construct(CommentAnchorData $anchor)
    {
        parent::__construct($anchor);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var CommentAnchorData $anchor */
        $anchor = $this->resource;

        return [
            'id' => $anchor->id,
            'comment_id' => $anchor->commentId,
            'document_id' => $anchor->documentId,
            'generation' => $anchor->generation,
            'source_position' => $anchor->sourcePosition,
            'schema_version' => $anchor->schemaVersion,
            'block_id' => $anchor->blockId,
            'selector_kind' => $anchor->selectorKind->value,
            'selector_payload' => $anchor->selectorPayload,
            'quote_text' => $anchor->quoteText,
            'anchor_state' => $anchor->anchorState->value,
            'version' => $anchor->version,
            'etag' => $anchor->etag,
            'created_at' => ReviewTags::iso($anchor->createdAt),
            'updated_at' => ReviewTags::iso($anchor->updatedAt),
        ];
    }
}
