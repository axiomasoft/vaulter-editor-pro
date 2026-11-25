<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Canonical RFC 9457 error codes owned by `axiomasoft/vaulter-editor-pro-exchange`.
 *
 * Each case carries its HTTP status, category and retry hint, so the exception, the Packman
 * problem definitions and the HTTP problem mapping read one source instead of repeating them.
 */
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

    public function httpStatus(): int
    {
        return match ($this) {
            self::JobNotFound => 404,
            self::ZipLimitExceeded => 413,
            self::RuntimeFailed => 503,
            self::IdempotencyConflict, self::JobNotReady, self::StalePreview, self::AlreadyApplied, self::LeaseLost => 409,
            self::InvalidCommand, self::UnsafeArchive, self::ProfileBlocked, self::SourceUnavailable,
            self::FormatDisabled, self::UnsupportedDocument => 422,
        };
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::JobNotFound => ErrorCategory::NotFound,
            self::RuntimeFailed => ErrorCategory::Unavailable,
            self::IdempotencyConflict, self::JobNotReady, self::StalePreview, self::AlreadyApplied, self::LeaseLost => ErrorCategory::Conflict,
            self::InvalidCommand, self::UnsafeArchive, self::ZipLimitExceeded, self::ProfileBlocked, self::SourceUnavailable,
            self::FormatDisabled, self::UnsupportedDocument => ErrorCategory::Validation,
        };
    }

    /** A transient state (job still running, worker lease fenced, runtime down): the same request may succeed later. */
    public function retryable(): bool
    {
        return match ($this) {
            self::JobNotReady, self::RuntimeFailed, self::LeaseLost => true,
            default => false,
        };
    }
}
