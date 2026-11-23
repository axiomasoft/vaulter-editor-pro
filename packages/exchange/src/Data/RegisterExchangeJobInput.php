<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;
use Vaulter\EditorPro\Exchange\Enums\ExchangeFormat;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobKind;

/** Validated input to register a DOCX import or export job. */
#[Api(since: '1.0')]
final readonly class RegisterExchangeJobInput
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $requestKey,
        public ExchangeJobKind $kind,
        public ExchangeFormat $format = ExchangeFormat::Docx,
        public ?string $profileId = null,
        public ?string $sourceBlobId = null,
        public ?string $sourceNodeId = null,
        public ?string $sourceRevisionId = null,
        public ?int $sourceGeneration = null,
        public ?int $sourcePosition = null,
        public ?string $rawBytes = null,
        public ?string $requestFingerprintHex = null,
        public array $options = [],
    ) {}
}
