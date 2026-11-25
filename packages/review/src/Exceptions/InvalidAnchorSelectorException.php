<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Vaulter\EditorPro\Review\Errors\ReviewErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api(since: '1.0')]
final class InvalidAnchorSelectorException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const ReviewErrorCode ERROR_CODE = ReviewErrorCode::InvalidSelector;

    private string $reason = 'invalid_selector';

    public static function because(string $message, string $reason = 'invalid_selector'): self
    {
        $exception = new self('[Vaulter Review] Invalid anchor selector: '.$message);
        $exception->reason = $reason;

        return $exception;
    }

    public function problemCode(): string
    {
        return self::ERROR_CODE->problemSlug();
    }

    public function httpStatus(): int
    {
        return self::ERROR_CODE->httpStatus();
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
            code: self::ERROR_CODE,
            category: self::ERROR_CODE->category(),
            details: ['reason' => self::boundedDetail($this->reason, 64)],
        );
    }
}
