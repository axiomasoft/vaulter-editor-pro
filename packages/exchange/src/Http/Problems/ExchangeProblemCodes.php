<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Problems;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Errors\ExchangeErrorCode;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;
use Vaulter\Http\Problems\Retryable;

/**
 * HTTP problem code mappings owned by `axiomasoft/vaulter-editor-pro-exchange`.
 *
 * Status and retry hint come from {@see ExchangeErrorCode}; only the receipt policy is HTTP-specific.
 */
#[Internal]
final class ExchangeProblemCodes implements ProblemCodeSource
{
    /** Deterministic refusals: replaying the same idempotent request must answer the same problem. */
    private const array CACHEABLE = [
        ExchangeErrorCode::InvalidCommand,
        ExchangeErrorCode::UnsafeArchive,
        ExchangeErrorCode::ZipLimitExceeded,
        ExchangeErrorCode::ProfileBlocked,
        ExchangeErrorCode::AlreadyApplied,
        ExchangeErrorCode::SourceUnavailable,
        ExchangeErrorCode::FormatDisabled,
        ExchangeErrorCode::UnsupportedDocument,
    ];

    public function translationNamespace(): string
    {
        return 'vaulter-editor-pro-exchange';
    }

    public function codes(): array
    {
        $codes = [];
        foreach (ExchangeErrorCode::cases() as $code) {
            $codes[$code->problemSlug()] = new ProblemSpec(
                $code->httpStatus(),
                ExchangeException::class,
                retryable: $code->retryable() ? Retryable::WithBackoff : Retryable::Never,
                receipt: in_array($code, self::CACHEABLE, true) ? ReceiptPolicy::Cacheable : ReceiptPolicy::Never,
            );
        }

        return $codes;
    }
}
