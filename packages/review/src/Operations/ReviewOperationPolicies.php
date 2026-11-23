<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Operations;

use Packman\Attributes\Internal;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/** Mutating operation policies declared by `review` module. */
#[Internal]
final class ReviewOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'review.pins.pin' => new OperationPolicy('review.revision_pin', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['review.pins.store']),
            'review.pins.unpin' => new OperationPolicy('review.revision_pin', ConcurrencyPolicy::Natural, IdempotencyPolicy::Receipt, ['review.pins.destroy']),
            'review.pins.release_expired' => new OperationPolicy('review.revision_pin', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural),

            'review.comments.add' => new OperationPolicy('review.comment_anchor', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['review.comments.store']),
            'review.comments.resolve' => new OperationPolicy('review.comment_anchor', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['review.comments.resolve']),
            'review.comments.reopen' => new OperationPolicy('review.comment_anchor', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['review.comments.reopen']),
            'review.comments.reanchor' => new OperationPolicy('review.comment_anchor', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['review.comments.reanchor']),
            'review.comments.delete' => new OperationPolicy('review.comment_anchor', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['review.comments.destroy']),
            'review.comments.reconcile_snapshot' => new OperationPolicy('review.comment_anchor', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural),
        ];
    }
}
