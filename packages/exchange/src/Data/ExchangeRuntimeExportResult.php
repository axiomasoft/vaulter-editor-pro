<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;

/** Conversion output produced by {@see DocxExchangeRuntime::export()}. */
#[Api(since: '1.0')]
final readonly class ExchangeRuntimeExportResult
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $semantic
     */
    public function __construct(
        public bool $blocked,
        public ?string $docxBytes,
        public ?string $docxSha256,
        public array $report,
        public ?array $semantic = null,
    ) {}
}
