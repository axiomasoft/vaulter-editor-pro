<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;

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
            [$title, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $code->httpStatus(),
                title: $title,
                retryable: $code->retryable(),
                category: $code->category(),
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: string, 1: list<string>} */
    private static function metadata(ExchangeErrorCode $code): array
    {
        return match ($code) {
            ExchangeErrorCode::JobNotFound => ['Exchange job not found', []],
            ExchangeErrorCode::IdempotencyConflict => ['Exchange request key conflict', []],
            ExchangeErrorCode::InvalidCommand => ['Invalid exchange command', ['reason']],
            ExchangeErrorCode::UnsafeArchive => ['Unsafe DOCX archive', ['reason', 'entry']],
            ExchangeErrorCode::ZipLimitExceeded => ['DOCX archive limits exceeded', ['reason', 'entry']],
            ExchangeErrorCode::ProfileBlocked => ['DOCX compatibility profile blocked', ['reason']],
            ExchangeErrorCode::JobNotReady => ['Exchange job is not ready', ['state']],
            ExchangeErrorCode::StalePreview => ['Import preview is stale for current document head', ['reason']],
            ExchangeErrorCode::AlreadyApplied => ['Exchange import job already applied', []],
            ExchangeErrorCode::SourceUnavailable => ['Exchange source blob or revision unavailable', ['reason']],
            ExchangeErrorCode::RuntimeFailed => ['DOCX runtime failed', ['reason']],
            ExchangeErrorCode::LeaseLost => ['Exchange worker lease lost', []],
            ExchangeErrorCode::FormatDisabled => ['Exchange format disabled', ['format']],
            ExchangeErrorCode::UnsupportedDocument => ['Document does not support DOCX exchange', ['reason']],
        };
    }
}
