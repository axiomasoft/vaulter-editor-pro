<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Vaulter\EditorPro\Exchange\Enums\ExchangeFormat;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;

/** Immutable public representation of one row in `v_document_exchange_jobs`. */
#[Api(since: '1.0')]
final readonly class ExchangeJobData
{
    /**
     * @param  array<string, mixed>|null  $diagnosticsReport
     */
    public function __construct(
        public string $id,
        public string $documentId,
        public string $requestKey,
        public ExchangeJobKind $kind,
        public ExchangeFormat $format,
        public string $profileId,
        public ExchangeJobState $state,
        public ?string $sourceBlobId,
        public ?string $sourceRevisionId,
        public ?int $sourceGeneration,
        public ?int $sourcePosition,
        public ?string $outputBlobId,
        public ?string $applyCommandId,
        public ?string $applyReceiptId,
        public ?array $diagnosticsReport,
        public ?string $errorCode,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $finishedAt = null,
    ) {}
}
