<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/** Canonical RFC 9457 error codes owned by `axiomasoft/vaulter-editor-pro-exchange`. */
#[Api(since: '1.0')]
enum ExchangeErrorCode: string implements ErrorCode
{
    case JobNotFound = 'exchange.exchange_job_not_found';
    case IdempotencyConflict = 'exchange.exchange_idempotency_conflict';
    case InvalidCommand = 'exchange.exchange_invalid_command';
    case UnsafeArchive = 'exchange.exchange_unsafe_archive';
    case ZipLimitExceeded = 'exchange.exchange_zip_limit_exceeded';
    case ProfileBlocked = 'exchange.exchange_profile_blocked';
    case JobNotReady = 'exchange.exchange_job_not_ready';
    case StalePreview = 'exchange.exchange_stale_preview';
    case AlreadyApplied = 'exchange.exchange_already_applied';
    case SourceUnavailable = 'exchange.exchange_source_unavailable';
    case RuntimeFailed = 'exchange.exchange_runtime_failed';
    case LeaseLost = 'exchange.exchange_lease_lost';
    case FormatDisabled = 'exchange.exchange_format_disabled';
    case UnsupportedDocument = 'exchange.exchange_unsupported_document';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public function problemSlug(): string
    {
        return substr($this->value, strlen('exchange.'));
    }
}
