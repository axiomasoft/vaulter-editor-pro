<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;

/** Durable receipt returned by `POST /documents/{node}/exchange-jobs/{job}/apply`. */
#[Api(since: '1.0')]
final readonly class ApplyExchangeReceiptData
{
    public function __construct(
        public ExchangeJobData $job,
        public string $applyCommandId,
        public ?string $applyReceiptId,
        public string $writeMode,
        public int $generation,
        public int $position,
        public bool $replayed = false,
        public bool $pendingCommit = false,
    ) {}
}
