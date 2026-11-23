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
use Vaulter\Support\Identity;

#[Api(since: '1.0')]
final class ReviewAnchorNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forComment(mixed $nodeId, mixed $commentId): self
    {
        return new self(sprintf(
            '[Vaulter Review] Comment range anchor was not found for comment [%s] on node [%s].',
            Identity::stringify($commentId),
            Identity::stringify($nodeId),
        ));
    }

    public function problemCode(): string
    {
        return 'review_anchor_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ReviewErrorCode::AnchorNotFound,
            category: ErrorCategory::NotFound,
        );
    }
}
