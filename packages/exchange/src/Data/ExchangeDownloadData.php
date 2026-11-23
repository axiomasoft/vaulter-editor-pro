<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;

/** Streamed export file descriptor (caller closes `$stream`). */
#[Api(since: '1.0')]
final readonly class ExchangeDownloadData
{
    /** @param resource $stream */
    public function __construct(
        public mixed $stream,
        public string $filename,
        public string $mimeType,
        public ?string $sha256 = null,
    ) {}
}
