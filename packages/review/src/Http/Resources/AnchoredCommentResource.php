<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Support\ReviewTags;

/** JSON representation of a node comment with its optional generation-scoped range anchor. */
#[Internal]
final class AnchoredCommentResource extends JsonResource
{
    public function __construct(AnchoredCommentData $item)
    {
        parent::__construct($item);
    }

    public function etag(): string
    {
        /** @var AnchoredCommentData $item */
        $item = $this->resource;

        return $item->etag;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var AnchoredCommentData $item */
        $item = $this->resource;
        $c = $item->comment;

        return [
            'id' => $c->id,
            'node_id' => $c->nodeId,
            'parent_id' => $c->parentId,
            'author' => [
                'type' => $c->authorType,
                'id' => $c->authorId,
            ],
            'body' => $c->body,
            'legacy_anchor' => $c->anchor,
            'anchor' => $item->anchor === null ? null : (new CommentAnchorResource($item->anchor))->toArray($request),
            'resolved_at' => ReviewTags::iso($c->resolvedAt),
            'resolved_by' => $c->resolvedBy,
            'version' => $c->version,
            'etag' => $item->etag,
            'created_at' => ReviewTags::iso($c->createdAt),
        ];
    }
}
