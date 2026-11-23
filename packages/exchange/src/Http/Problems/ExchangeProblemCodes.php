<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Problems;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Errors\ExchangeErrorCode;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;

/** HTTP problem code mappings owned by `axiomasoft/vaulter-editor-pro-exchange`. */
#[Internal]
final class ExchangeProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-editor-pro-exchange';
    }

    public function codes(): array
    {
        return [
            ExchangeErrorCode::JobNotFound->problemSlug() => new ProblemSpec(404, ExchangeException::class),
            ExchangeErrorCode::IdempotencyConflict->problemSlug() => new ProblemSpec(409, ExchangeException::class),
            ExchangeErrorCode::InvalidCommand->problemSlug() => new ProblemSpec(422, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::UnsafeArchive->problemSlug() => new ProblemSpec(422, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::ZipLimitExceeded->problemSlug() => new ProblemSpec(413, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::ProfileBlocked->problemSlug() => new ProblemSpec(422, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::JobNotReady->problemSlug() => new ProblemSpec(409, ExchangeException::class),
            ExchangeErrorCode::StalePreview->problemSlug() => new ProblemSpec(409, ExchangeException::class),
            ExchangeErrorCode::AlreadyApplied->problemSlug() => new ProblemSpec(409, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::SourceUnavailable->problemSlug() => new ProblemSpec(422, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::RuntimeFailed->problemSlug() => new ProblemSpec(503, ExchangeException::class),
            ExchangeErrorCode::LeaseLost->problemSlug() => new ProblemSpec(409, ExchangeException::class),
            ExchangeErrorCode::FormatDisabled->problemSlug() => new ProblemSpec(422, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
            ExchangeErrorCode::UnsupportedDocument->problemSlug() => new ProblemSpec(422, ExchangeException::class, receipt: ReceiptPolicy::Cacheable),
        ];
    }
}
