<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;
use Vaulter\EditorPro\Review\Enums\SelectorKind;

/** Input selector descriptor for creating or reanchoring a generation-scoped comment range. */
#[Api(since: '1.0')]
final readonly class CommentAnchorInput
{
    /** @param array<string, mixed> $selectorPayload */
    public function __construct(
        public int $generation,
        public int $sourcePosition,
        public int $schemaVersion,
        public string $blockId,
        public SelectorKind $selectorKind,
        public array $selectorPayload,
        public string $quoteText,
        public ?string $pinRevisionId = null,
    ) {}
}
