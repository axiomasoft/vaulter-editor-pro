<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Support\ReviewTags;

#[Api(since: '1.0')]
final readonly class TextSuggestion
{
    /**
     * @param  array<string, mixed>  $targetSelector
     * @param  array<string, mixed>  $payload
     */
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
        public array $targetSelector = [],
        public array $payload = [],
        public ?string $conflictReason = null,
        public ?string $conflictWithId = null,
        public ?string $journalCommandId = null,
        public ?string $journalState = null,
        public ?int $committedPosition = null,
    ) {}

    public function isStructural(): bool
    {
        return ! in_array($this->kind, ['insert', 'delete', 'replace'], true);
    }

    public function isPendingJournal(): bool
    {
        return $this->state === 'open' && $this->journalCommandId !== null && $this->journalState === 'running';
    }

    public function etag(): string
    {
        return ReviewTags::suggestion($this->id, $this->version);
    }
}
