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
final class InvalidCommentStateException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const ReviewErrorCode ERROR_CODE = ReviewErrorCode::InvalidCommentState;

    public static function replyCannotCarryRangeAnchor(): self
    {
        return new self('[Vaulter Review] Only root comments can carry a document range anchor.');
    }

    public static function targetIsNotDocument(): self
    {
        return new self('[Vaulter Review] Range comment anchors are only supported on document nodes.');
    }

    public static function staleGeneration(int $expectedGeneration, int $currentGeneration): self
    {
        return new self(sprintf(
            '[Vaulter Review] Anchor generation [%d] does not match current document generation [%d].',
            $expectedGeneration,
            $currentGeneration,
        ));
    }

    public function problemCode(): string
    {
        return self::ERROR_CODE->problemSlug();
    }

    public function httpStatus(): int
    {
        return self::ERROR_CODE->httpStatus();
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: self::ERROR_CODE,
            category: self::ERROR_CODE->category(),
        );
    }
}
