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
use Vaulter\Support\Identity;

#[Api(since: '1.0')]
final class RevisionPinNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const ReviewErrorCode ERROR_CODE = ReviewErrorCode::PinNotFound;

    public static function forRevision(mixed $nodeId, mixed $revisionId): self
    {
        return new self(sprintf(
            '[Vaulter Review] Revision pin for revision [%s] on node [%s] was not found.',
            Identity::stringify($revisionId),
            Identity::stringify($nodeId),
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
