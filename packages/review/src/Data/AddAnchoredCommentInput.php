<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;

/** Input payload for adding a node comment (plain or with a generation-scoped range anchor). */
#[Api(since: '1.0')]
final readonly class AddAnchoredCommentInput
{
    public function __construct(
        public string $body,
        public string|int|null $parentId = null,
        public ?CommentAnchorInput $anchor = null,
    ) {}
}
