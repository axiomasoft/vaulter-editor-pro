<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Support;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Operations\AnchoredCommentOperations;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Support\Identity;

/**
 * Evaluates strong `If-Match` preconditions on conditional review comment mutations
 * strictly after resource discovery and caller authorization.
 */
#[Internal]
final readonly class ReviewPreconditions
{
    public function __construct(private Vaulter $vaulter) {}

    public function expectedCommentVersion(
        Request $request,
        DriveContext $context,
        string $nodeId,
        string $commentId,
        bool $delete,
        bool $authorOnly = false,
    ): int {
        return $this->evaluate($request, $context, $nodeId, $commentId, forAnchor: false, delete: $delete, authorOnly: $authorOnly);
    }

    public function expectedAnchorVersion(
        Request $request,
        DriveContext $context,
        string $nodeId,
        string $commentId,
    ): int {
        return $this->evaluate($request, $context, $nodeId, $commentId, forAnchor: true, delete: false, authorOnly: false);
    }

    private function evaluate(
        Request $request,
        DriveContext $context,
        string $nodeId,
        string $commentId,
        bool $forAnchor,
        bool $delete,
        bool $authorOnly = false,
    ): int {
        $held = null;
        try {
            $item = $this->authorizeAndFetch($context, $nodeId, $commentId, $delete, $authorOnly);
            if ($forAnchor && $item->anchor !== null) {
                $currentTag = $item->anchor->etag;
                $held = $item->anchor->version;
            } else {
                $currentTag = $item->etag;
                $held = $item->comment->version;
            }

            if (! self::matchesIfMatch($request, $currentTag)) {
                throw StaleWriteException::of('documents.comment', $commentId);
            }

            return $held;
        } catch (StaleWriteException|NodeNotFoundException|CommentNotFoundException $refusal) {
            if (! $request->headers->has('Idempotency-Key')) {
                throw $refusal;
            }

            return $held === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX;
        }
    }

    private function authorizeAndFetch(
        DriveContext $context,
        string $nodeId,
        string $commentId,
        bool $delete,
        bool $authorOnly = false,
    ): AnchoredCommentData {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $context);
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }

        $drive->nodes()->find($nodeId);
        $ops = $drive->extension(AnchoredCommentOperations::class);
        $item = $ops->find($nodeId, $commentId);

        $actor = $context->actor->ref();
        $isAuthor = ! $context->actor->isSystem()
            && $actor->type === $item->comment->authorType
            && Identity::stringify($actor->id) === $item->comment->authorId;

        $abilities = $drive->nodes()->abilities($nodeId)->flags;
        $needed = $delete ? Ability::Manage : Ability::Edit;
        if (! $delete && ! ($abilities[Ability::Comment->value] ?? false)) {
            throw PermissionDeniedException::forAbility(Ability::Comment->value);
        }
        if ($authorOnly && ! $isAuthor) {
            throw PermissionDeniedException::forAction('comment.edit');
        }
        if (! $isAuthor && ! ($abilities[$needed->value] ?? false)) {
            throw PermissionDeniedException::forAbility($needed->value);
        }

        return $item;
    }

    private static function matchesIfMatch(Request $request, string $currentEtag): bool
    {
        $header = $request->headers->get('If-Match') ?? $request->headers->get('X-Vaulter-If-Match');
        if ($header === null || trim($header) === '') {
            throw PreconditionRequiredException::missing('If-Match');
        }

        preg_match_all('/\s*(\*|(?:W\/)?"[^"]*")\s*(?:,|$)/', $header, $matches);
        $tags = $matches[1];
        if ($tags === []) {
            throw PreconditionRequiredException::missing('If-Match');
        }
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'W/')) {
                throw StaleWriteException::of('documents.comment', 'weak_etag');
            }
            if ($tag === '*') {
                throw PreconditionRequiredException::wildcard();
            }
        }

        return in_array($currentEtag, $tags, true);
    }
}
