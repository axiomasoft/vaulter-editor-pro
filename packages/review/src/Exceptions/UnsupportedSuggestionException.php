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

/** Raised prior to mutation when a suggestion requests an unsupported structure, node-type change, or capability. */
#[Api(since: '1.0')]
final class UnsupportedSuggestionException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const ReviewErrorCode ERROR_CODE = ReviewErrorCode::UnsupportedSuggestion;

    private string $reason = 'unsupported_suggestion_structure';

    public static function because(string $message, string $reason = 'unsupported_suggestion_structure'): self
    {
        $exception = new self('[Vaulter Review] Unsupported suggestion: '.$message);
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
