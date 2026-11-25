<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Problems;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Errors\ReviewErrorCode;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;
use Vaulter\EditorPro\Review\Exceptions\InvalidCommentStateException;
use Vaulter\EditorPro\Review\Exceptions\ReviewAnchorNotFoundException;
use Vaulter\EditorPro\Review\Exceptions\RevisionPinNotFoundException;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;

/**
 * HTTP problem code mappings owned by `axiomasoft/vaulter-editor-pro-review`.
 *
 * Slug and status come from {@see ReviewErrorCode}; only the exception class and the receipt policy are HTTP-specific.
 */
#[Internal]
final class ReviewProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-editor-pro-review';
    }

    public function codes(): array
    {
        $codes = [];
        foreach (ReviewErrorCode::cases() as $code) {
            [$exception, $receipt] = match ($code) {
                ReviewErrorCode::AnchorNotFound => [ReviewAnchorNotFoundException::class, ReceiptPolicy::Never],
                ReviewErrorCode::InvalidSelector => [InvalidAnchorSelectorException::class, ReceiptPolicy::Cacheable],
                ReviewErrorCode::PinNotFound => [RevisionPinNotFoundException::class, ReceiptPolicy::Never],
                ReviewErrorCode::InvalidCommentState => [InvalidCommentStateException::class, ReceiptPolicy::Cacheable],
                ReviewErrorCode::SuggestionConflict => [SuggestionConflictException::class, ReceiptPolicy::Cacheable],
                ReviewErrorCode::UnsupportedSuggestion => [UnsupportedSuggestionException::class, ReceiptPolicy::Cacheable],
            };
            $codes[$code->problemSlug()] = new ProblemSpec($code->httpStatus(), $exception, receipt: $receipt);
        }

        return $codes;
    }
}
