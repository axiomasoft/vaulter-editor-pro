<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/** Canonical error codes owned by `axiomasoft/vaulter-editor-pro-review`. */
#[Api(since: '1.0')]
enum ReviewErrorCode: string implements ErrorCode
{
    case AnchorNotFound = 'review.review_anchor_not_found';
    case InvalidSelector = 'review.invalid_anchor_selector';
    case PinNotFound = 'review.revision_pin_not_found';
    case InvalidCommentState = 'review.invalid_comment_state';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
