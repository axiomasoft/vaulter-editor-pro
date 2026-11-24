<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;

/** Normalized conversion output produced by {@see DocxExchangeRuntime::import()}. */
#[Api(since: '1.0')]
final readonly class ExchangeRuntimeImportResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $semantic
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $discussions
     * @param  list<array<string, mixed>>  $revisions
     */
    public function __construct(
        public bool $blocked,
        public string $sourceSha256,
        public array $document,
        public array $report,
        public ?array $semantic = null,
        public array $sections = [],
        public array $discussions = [],
        public array $revisions = [],
    ) {}
}
