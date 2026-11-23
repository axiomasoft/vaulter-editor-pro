<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Problems;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;
use Vaulter\EditorPro\Review\Exceptions\InvalidCommentStateException;
use Vaulter\EditorPro\Review\Exceptions\ReviewAnchorNotFoundException;
use Vaulter\EditorPro\Review\Exceptions\RevisionPinNotFoundException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;

/** HTTP problem code mappings owned by `axiomasoft/vaulter-editor-pro-review`. */
#[Internal]
final class ReviewProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-editor-pro-review';
    }

    public function codes(): array
    {
        return [
            'review_anchor_not_found' => new ProblemSpec(404, ReviewAnchorNotFoundException::class),
            'invalid_anchor_selector' => new ProblemSpec(422, InvalidAnchorSelectorException::class, receipt: ReceiptPolicy::Cacheable),
            'revision_pin_not_found' => new ProblemSpec(404, RevisionPinNotFoundException::class),
            'invalid_comment_state' => new ProblemSpec(409, InvalidCommentStateException::class, receipt: ReceiptPolicy::Cacheable),
        ];
    }
}
