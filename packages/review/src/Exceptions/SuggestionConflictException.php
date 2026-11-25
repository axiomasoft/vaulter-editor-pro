<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Throwable;
use Vaulter\EditorPro\Review\Errors\ReviewErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** The selection or its target must be refreshed or resolved before a decision can be made. */
#[Api(since: '1.0')]
final class SuggestionConflictException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const ReviewErrorCode ERROR_CODE = ReviewErrorCode::SuggestionConflict;

    public readonly string $reason;

    public function __construct(
        string $message = 'Suggestion conflict.',
        string $reason = 'suggestion_conflict',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        $this->reason = $reason;
        parent::__construct($message, $code, $previous);
    }

    public static function because(string $message, string $reason = 'suggestion_conflict'): self
    {
        return new self($message, $reason);
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
