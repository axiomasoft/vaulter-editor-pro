<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\EditorPro\Review\Errors\ReviewErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api(since: '1.0')]
final class InvalidAnchorSelectorException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private string $reason = 'invalid_selector';

    public static function because(string $message, string $reason = 'invalid_selector'): self
    {
        $exception = new self('[Vaulter Review] Invalid anchor selector: '.$message);
        $exception->reason = $reason;

        return $exception;
    }

    public function problemCode(): string
    {
        return 'invalid_anchor_selector';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return ['reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ReviewErrorCode::InvalidSelector,
            category: ErrorCategory::Validation,
            details: ['reason' => self::boundedDetail($this->reason, 64)],
        );
    }
}
