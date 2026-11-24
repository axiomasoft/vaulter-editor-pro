<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;

/** Job status + diagnostics + optional preview payload returned by `GET .../exchange-jobs/{job}`. */
#[Api(since: '1.0')]
final readonly class ExchangePreviewData
{
    /**
     * @param  array<string, mixed>|null  $previewDocument
     * @param  array<string, mixed>|null  $semantic
     */
    public function __construct(
        public ExchangeJobData $job,
        public ?array $previewDocument = null,
        public ?array $semantic = null,
        public ?string $writeMode = null,
        public ?int $appliedGeneration = null,
        public ?int $appliedPosition = null,
    ) {}
}
