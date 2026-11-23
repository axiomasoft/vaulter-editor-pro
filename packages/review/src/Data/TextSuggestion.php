<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;

#[Api(since: '1.0')]
final readonly class TextSuggestion
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $groupId,
        public ?string $proposalId,
        public string $documentId,
        public int $generation,
        public int $basePosition,
        public int $schemaVersion,
        public string $authorSubject,
        public string $kind,
        public string $targetBlockId,
        public int $from,
        public int $to,
        public ?string $parentId,
        public bool $conflicted,
        public string $before,
        public string $after,
        public string $state,
        public int $version,
        public ?string $receiptId,
        public ?string $decidedBySubject,
        public ?DateTimeImmutable $decidedAt,
    ) {}
}
