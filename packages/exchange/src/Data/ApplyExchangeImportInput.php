<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Data;

use Packman\Attributes\Api;

/** Validated input for explicitly applying an import preview job. */
#[Api(since: '1.0')]
final readonly class ApplyExchangeImportInput
{
    public function __construct(
        public string $applyCommandId,
        public ?int $expectedGeneration = null,
        public ?int $expectedPosition = null,
    ) {}
}
