<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Canonical error codes owned by `axiomasoft/vaulter-editor-pro-review`.
 *
 * Each case carries its HTTP status and category, so the exceptions, the Packman problem
 * definitions and the HTTP problem mapping read one source instead of repeating them.
 */
#[Api(since: '1.0')]
enum ReviewErrorCode: string implements ErrorCode
{
    case AnchorNotFound = 'review.review_anchor_not_found';
    case InvalidSelector = 'review.invalid_anchor_selector';
    case PinNotFound = 'review.revision_pin_not_found';
    case InvalidCommentState = 'review.invalid_comment_state';
    case SuggestionConflict = 'review.suggestion_conflict';
    case UnsupportedSuggestion = 'review.unsupported_suggestion';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public function problemSlug(): string
    {
        return substr($this->value, strlen('review.'));
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::AnchorNotFound, self::PinNotFound => 404,
            self::InvalidCommentState, self::SuggestionConflict => 409,
            self::InvalidSelector, self::UnsupportedSuggestion => 422,
        };
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::AnchorNotFound, self::PinNotFound => ErrorCategory::NotFound,
            self::InvalidCommentState, self::SuggestionConflict => ErrorCategory::Conflict,
            self::InvalidSelector, self::UnsupportedSuggestion => ErrorCategory::Validation,
        };
    }
}
