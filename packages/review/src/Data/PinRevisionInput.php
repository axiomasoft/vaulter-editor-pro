<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Vaulter\EditorPro\Review\Enums\PinReason;

/** Input parameters for pinning an immutable document revision against pruning or compaction. */
#[Api(since: '1.0')]
final readonly class PinRevisionInput
{
    public function __construct(
        public PinReason $reason = PinReason::ManualVersion,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
