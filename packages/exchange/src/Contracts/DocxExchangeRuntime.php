<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeExportResult;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeImportResult;

/** Out-of-process DOCX <-> TipTap conversion runtime contract. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DocxExchangeRuntime
{
    /**
     * @param  array<string, mixed>|null  $sourceMarker
     * @param  array<string, mixed>  $options
     */
    public function importDocx(
        string $docxBytes,
        string $profileId,
        ?array $sourceMarker = null,
        array $options = [],
    ): ExchangeRuntimeImportResult;

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>|null  $sourceMarker
     * @param  list<array<string, mixed>>  $discussions
     * @param  array<string, mixed>  $options
     */
    public function exportDocx(
        array $document,
        string $profileId,
        ?array $sourceMarker = null,
        array $discussions = [],
        array $options = [],
    ): ExchangeRuntimeExportResult;
}
