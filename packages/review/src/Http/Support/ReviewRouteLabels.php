<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Support;

use Packman\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;

/** Metric labels and route path patterns for `vaulter-editor-pro-review` endpoints. */
#[Internal]
final class ReviewRouteLabels implements RouteLabelSource
{
    public function operations(): array
    {
        return [
            'vaulter.v1.review.pins.index' => 'doc_read',
            'vaulter.v1.review.pins.store' => 'doc_write',
            'vaulter.v1.review.pins.destroy' => 'doc_write',
            'vaulter.v1.review.revisions.preview' => 'doc_read',
            'vaulter.v1.review.revisions.compare' => 'doc_read',
            'vaulter.v1.review.comments.index' => 'comment',
            'vaulter.v1.review.comments.store' => 'comment',
            'vaulter.v1.review.comments.resolve' => 'comment',
            'vaulter.v1.review.comments.reopen' => 'comment',
            'vaulter.v1.review.comments.reanchor' => 'comment',
            'vaulter.v1.review.comments.destroy' => 'comment',
            'vaulter.v1.review.comments.update' => 'comment',
            'vaulter.v1.review.comments.archive' => 'comment',
            'vaulter.v1.review.suggestions.capabilities' => 'doc_read',
            'vaulter.v1.review.suggestions.index' => 'doc_read',
            'vaulter.v1.review.suggestions.store' => 'doc_write',
            'vaulter.v1.review.suggestions.accept' => 'doc_write',
            'vaulter.v1.review.suggestions.reject' => 'doc_write',
            'vaulter.v1.review.suggestions.groups.accept' => 'doc_write',
            'vaulter.v1.review.suggestions.groups.reject' => 'doc_write',
            'vaulter.v1.review.suggestions.bulk.accept' => 'doc_write',
            'vaulter.v1.review.suggestions.bulk.reject' => 'doc_write',
        ];
    }

    public function operationPaths(): array
    {
        return [
            ['methods' => ['GET'], 'pattern' => 'v1/review/documents/{id}/pins', 'operation' => 'doc_read'],
            ['methods' => ['POST', 'DELETE'], 'pattern' => 'v1/review/documents/{id}/revisions/{id}/pin', 'operation' => 'doc_write'],
            ['methods' => ['GET'], 'pattern' => 'v1/review/documents/{id}/revisions/{id}/preview', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'v1/review/documents/{id}/compare', 'operation' => 'doc_read'],
            ['methods' => ['GET', 'POST'], 'pattern' => 'v1/review/nodes/{id}/comments', 'operation' => 'comment'],
            ['methods' => ['DELETE', 'PATCH'], 'pattern' => 'v1/review/nodes/{id}/comments/{id}', 'operation' => 'comment'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/nodes/{id}/comments/{id}/archive', 'operation' => 'comment'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/nodes/{id}/comments/{id}/resolve', 'operation' => 'comment'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/nodes/{id}/comments/{id}/reopen', 'operation' => 'comment'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/nodes/{id}/comments/{id}/reanchor', 'operation' => 'comment'],
            ['methods' => ['GET'], 'pattern' => 'v1/review/documents/{id}/suggestions/capabilities', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'v1/review/documents/{id}/suggestions', 'operation' => 'doc_read'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions/{id}/accept', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions/{id}/reject', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions/groups/{id}/accept', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions/groups/{id}/reject', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions/bulk/accept', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/review/documents/{id}/suggestions/bulk/reject', 'operation' => 'doc_write'],
        ];
    }

    public function throttled(): array
    {
        return [];
    }
}
