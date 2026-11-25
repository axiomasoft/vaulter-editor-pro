<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;

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
            [$title, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $code->httpStatus(),
                title: $title,
                retryable: false,
                category: $code->category(),
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: string, 1: list<string>} */
    private static function metadata(ReviewErrorCode $code): array
    {
        return match ($code) {
            ReviewErrorCode::AnchorNotFound => ['Review anchor not found', []],
            ReviewErrorCode::InvalidSelector => ['Invalid anchor selector', ['reason']],
            ReviewErrorCode::PinNotFound => ['Revision pin not found', []],
            ReviewErrorCode::InvalidCommentState => ['Invalid comment state', []],
            ReviewErrorCode::SuggestionConflict => ['Suggestion conflict', ['reason']],
            ReviewErrorCode::UnsupportedSuggestion => ['Unsupported suggestion structure', ['reason']],
        };
    }
}
