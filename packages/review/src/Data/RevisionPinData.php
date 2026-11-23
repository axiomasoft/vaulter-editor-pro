<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Vaulter\EditorPro\Review\Enums\PinReason;

/** Public read-only snapshot of a `v_document_revision_pins` record. */
#[Api(since: '1.0')]
final readonly class RevisionPinData
{
    public function __construct(
        public string $revisionId,
        public string $documentId,
        public PinReason $pinReason,
        public int $sourceGeneration,
        public int $sourcePosition,
        public string $pinnedBySubject,
        public DateTimeImmutable $pinnedAt,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function isExpired(?DateTimeImmutable $at = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= ($at ?? new DateTimeImmutable);
    }
}
