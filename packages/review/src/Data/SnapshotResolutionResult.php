<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;
use Vaulter\EditorPro\Review\Enums\AnchorState;

/** Result of resolving or remapping a snapshot comment anchor after an edit. */
#[Api(since: '1.0')]
final readonly class SnapshotResolutionResult
{
    /** @param array<string, mixed> $selectorPayload */
    public function __construct(
        public AnchorState $state,
        public array $selectorPayload,
        public string $quoteText,
        public bool $changed,
    ) {}
}
