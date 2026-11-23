<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Throwable;
use Vaulter\EditorPro\Exchange\Errors\ExchangeErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api(since: '1.0')]
final class ExchangeException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    /** @param array<string, scalar> $details */
    private function __construct(
        private readonly ExchangeErrorCode $exchangeCode,
        private readonly int $status,
        private readonly ErrorCategory $category,
        private readonly bool $retryable,
        string $message,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct('[Vaulter Exchange] '.$message, 0, $previous);
    }

    public static function jobNotFound(string $jobId = ''): self
    {
        $suffix = $jobId !== '' ? ' ('.$jobId.')' : '';

        return new self(
            exchangeCode: ExchangeErrorCode::JobNotFound,
            status: 404,
            category: ErrorCategory::NotFound,
            retryable: false,
            message: 'Exchange job not found'.$suffix.'.',
        );
    }

    public static function idempotencyConflict(string $reason = 'request_key_mismatch'): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::IdempotencyConflict,
            status: 409,
            category: ErrorCategory::Conflict,
            retryable: false,
            message: 'Exchange request_key was already registered with different parameters ('.$reason.').',
        );
    }

    public static function invalidCommand(string $message, string $reason = 'invalid_command', ?Throwable $previous = null): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::InvalidCommand,
            status: 422,
            category: ErrorCategory::Validation,
            retryable: false,
            message: $message,
            details: ['reason' => self::boundedDetail($reason, 64)],
            previous: $previous,
        );
    }

    public static function unsafeArchive(string $reason, string $entry = '', string $detailMessage = ''): self
    {
        $msg = $detailMessage !== '' ? $detailMessage : 'Unsafe DOCX archive ('.$reason.').';
        $details = ['reason' => self::boundedDetail($reason, 64)];
        if ($entry !== '') {
            $details['entry'] = self::boundedDetail($entry, 191);
        }

        return new self(
            exchangeCode: ExchangeErrorCode::UnsafeArchive,
            status: 422,
            category: ErrorCategory::Validation,
            retryable: false,
            message: $msg,
            details: $details,
        );
    }

    public static function zipLimitExceeded(string $reason, string $entry = ''): self
    {
        $details = ['reason' => self::boundedDetail($reason, 64)];
        if ($entry !== '') {
            $details['entry'] = self::boundedDetail($entry, 191);
        }

        return new self(
            exchangeCode: ExchangeErrorCode::ZipLimitExceeded,
            status: 413,
            category: ErrorCategory::Validation,
            retryable: false,
            message: 'DOCX archive exceeded security quota limits ('.$reason.').',
            details: $details,
        );
    }

    public static function profileBlocked(string $reason = 'profile_blocked'): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::ProfileBlocked,
            status: 422,
            category: ErrorCategory::Validation,
            retryable: false,
            message: 'DOCX profile blocked the operation ('.$reason.').',
            details: ['reason' => self::boundedDetail($reason, 64)],
        );
    }

    public static function jobNotReady(string $state): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::JobNotReady,
            status: 409,
            category: ErrorCategory::Conflict,
            retryable: true,
            message: 'Exchange job is not ready in state "'.$state.'".',
            details: ['state' => self::boundedDetail($state, 32)],
        );
    }

    public static function stalePreview(string $reason = 'head_changed'): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::StalePreview,
            status: 409,
            category: ErrorCategory::Conflict,
            retryable: false,
            message: 'Document head changed after preview was generated ('.$reason.').',
            details: ['reason' => self::boundedDetail($reason, 64)],
        );
    }

    public static function alreadyApplied(): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::AlreadyApplied,
            status: 409,
            category: ErrorCategory::Conflict,
            retryable: false,
            message: 'Exchange import job was already applied with a different apply_command_id.',
        );
    }

    public static function sourceUnavailable(string $reason = 'source_unavailable', ?Throwable $previous = null): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::SourceUnavailable,
            status: 422,
            category: ErrorCategory::Validation,
            retryable: false,
            message: 'Exchange source is missing or unavailable ('.$reason.').',
            details: ['reason' => self::boundedDetail($reason, 64)],
            previous: $previous,
        );
    }

    public static function runtimeFailed(string $reason = 'runtime_failed', string $detail = '', ?Throwable $previous = null): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::RuntimeFailed,
            status: 503,
            category: ErrorCategory::Unavailable,
            retryable: true,
            message: $detail !== '' ? $detail : 'DOCX conversion runtime failed ('.$reason.').',
            details: ['reason' => self::boundedDetail($reason, 64)],
            previous: $previous,
        );
    }

    public static function leaseLost(): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::LeaseLost,
            status: 409,
            category: ErrorCategory::Conflict,
            retryable: true,
            message: 'Exchange worker lease expired or was fenced.',
        );
    }

    public static function formatDisabled(string $format): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::FormatDisabled,
            status: 422,
            category: ErrorCategory::Validation,
            retryable: false,
            message: 'Exchange format "'.$format.'" is disabled.',
            details: ['format' => self::boundedDetail($format, 16)],
        );
    }

    public static function unsupportedDocument(string $reason = 'unsupported_document'): self
    {
        return new self(
            exchangeCode: ExchangeErrorCode::UnsupportedDocument,
            status: 422,
            category: ErrorCategory::Validation,
            retryable: false,
            message: 'Document does not support DOCX exchange ('.$reason.').',
            details: ['reason' => self::boundedDetail($reason, 64)],
        );
    }

    public function exchangeCode(): ExchangeErrorCode
    {
        return $this->exchangeCode;
    }

    public function problemCode(): string
    {
        return $this->exchangeCode->problemSlug();
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return $this->details;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: $this->exchangeCode,
            category: $this->category,
            retryable: $this->retryable,
            details: $this->details,
        );
    }
}
