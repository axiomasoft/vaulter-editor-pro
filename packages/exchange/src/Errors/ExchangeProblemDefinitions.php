<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/** Registers RFC 9457 problem definitions for `vaulter-editor-pro-exchange`. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ExchangeProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        foreach (ExchangeErrorCode::cases() as $code) {
            [$status, $title, $retryable, $category, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $status,
                title: $title,
                retryable: $retryable,
                category: $category,
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: int, 1: string, 2: bool, 3: ErrorCategory, 4: list<string>} */
    private static function metadata(ExchangeErrorCode $code): array
    {
        return match ($code) {
            ExchangeErrorCode::JobNotFound => [404, 'Exchange job not found', false, ErrorCategory::NotFound, []],
            ExchangeErrorCode::IdempotencyConflict => [409, 'Exchange request key conflict', false, ErrorCategory::Conflict, []],
            ExchangeErrorCode::InvalidCommand => [422, 'Invalid exchange command', false, ErrorCategory::Validation, ['reason']],
            ExchangeErrorCode::UnsafeArchive => [422, 'Unsafe DOCX archive', false, ErrorCategory::Validation, ['reason', 'entry']],
            ExchangeErrorCode::ZipLimitExceeded => [413, 'DOCX archive limits exceeded', false, ErrorCategory::Validation, ['reason', 'entry']],
            ExchangeErrorCode::ProfileBlocked => [422, 'DOCX compatibility profile blocked', false, ErrorCategory::Validation, ['reason']],
            ExchangeErrorCode::JobNotReady => [409, 'Exchange job is not ready', true, ErrorCategory::Conflict, ['state']],
            ExchangeErrorCode::StalePreview => [409, 'Import preview is stale for current document head', false, ErrorCategory::Conflict, ['reason']],
            ExchangeErrorCode::AlreadyApplied => [409, 'Exchange import job already applied', false, ErrorCategory::Conflict, []],
            ExchangeErrorCode::SourceUnavailable => [422, 'Exchange source blob or revision unavailable', false, ErrorCategory::Validation, ['reason']],
            ExchangeErrorCode::RuntimeFailed => [503, 'DOCX runtime failed', true, ErrorCategory::Unavailable, ['reason']],
            ExchangeErrorCode::LeaseLost => [409, 'Exchange worker lease lost', true, ErrorCategory::Conflict, []],
            ExchangeErrorCode::FormatDisabled => [422, 'Exchange format disabled', false, ErrorCategory::Validation, ['format']],
            ExchangeErrorCode::UnsupportedDocument => [422, 'Document does not support DOCX exchange', false, ErrorCategory::Validation, ['reason']],
        };
    }
}
