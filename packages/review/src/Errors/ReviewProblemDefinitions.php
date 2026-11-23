<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/** Registers RFC 9457 problem definitions for review error codes. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ReviewProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        foreach (ReviewErrorCode::cases() as $code) {
            [$status, $title, $retryable, $category, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $status,
                title: $title,
                retryable: $retryable,
                category: $category,
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: int, 1: string, 2: bool, 3: ErrorCategory, 4: list<string>} */
    private static function metadata(ReviewErrorCode $code): array
    {
        return match ($code) {
            ReviewErrorCode::AnchorNotFound => [404, 'Review anchor not found', false, ErrorCategory::NotFound, []],
            ReviewErrorCode::InvalidSelector => [422, 'Invalid anchor selector', false, ErrorCategory::Validation, ['reason']],
            ReviewErrorCode::PinNotFound => [404, 'Revision pin not found', false, ErrorCategory::NotFound, []],
            ReviewErrorCode::InvalidCommentState => [409, 'Invalid comment state', false, ErrorCategory::Conflict, []],
            ReviewErrorCode::SuggestionConflict => [409, 'Suggestion conflict', false, ErrorCategory::Conflict, ['reason']],
            ReviewErrorCode::UnsupportedSuggestion => [422, 'Unsupported suggestion structure', false, ErrorCategory::Validation, ['reason']],
        };
    }
}
