<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Idempotency;

use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Support\Identity;

/**
 * Re-authorizes idempotent replay for review comment mutations (`add`, `reopen`, `reanchor`).
 */
#[Internal]
final class ReviewCommentReplayAuthorizer implements ReplayAuthorizer
{
    public const string AUTHORED = 'node-author';

    public const string VERB_ADD = 'add';

    public const string VERB_EDIT = 'edit';

    public const string VERB_ARCHIVE = 'archive';

    public const string VERB_REOPEN = 'reopen';

    public const string VERB_REANCHOR = 'reanchor';

    private bool $provedByAuthorship = false;

    public function __construct(
        private readonly DriveContext $context,
        private readonly Vaulter $vaulter,
        private readonly DocumentsOptions $documentsOptions,
        private readonly string $verb,
        private readonly string $nodeId,
    ) {}

    public function provedByAuthorship(): void
    {
        if ($this->verb !== self::VERB_ADD) {
            $this->provedByAuthorship = true;
        }
    }

    public function scope(mixed $result): ResourceRef
    {
        if ($result instanceof AnchoredCommentData) {
            $isAuthor = $this->verb !== self::VERB_ADD && $this->isActorAuthor($result->comment->authorType, $result->comment->authorId);

            return new ResourceRef($isAuthor ? self::AUTHORED : 'node', $result->comment->nodeId);
        }

        return new ResourceRef($this->provedByAuthorship ? self::AUTHORED : 'node', $this->nodeId);
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $targetNodeId = $resource?->kind === 'node'
            ? $resource->id
            : ($scope !== null && in_array($scope->kind, ['node', self::AUTHORED], true) ? $scope->id : $this->nodeId);

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }

        // Enforces View + live node existence (trashed/hidden -> NodeNotFoundException).
        $drive->nodes()->find($targetNodeId);

        if (! $this->documentsOptions->comments->enabled || ! $drive->profile()->commentsEnabled()) {
            throw ProfileRestrictionException::forOperation('comments');
        }

        $abilities = $drive->nodes()->abilities($targetNodeId)->flags;
        if (! ($abilities[Ability::Comment->value] ?? false)) {
            throw PermissionDeniedException::forAbility(Ability::Comment->value);
        }

        $authored = $scope?->kind === self::AUTHORED;
        if ($this->verb === self::VERB_EDIT && ! $authored) {
            throw PermissionDeniedException::forAction('comment.edit');
        }
        if ($this->verb !== self::VERB_ADD && ! $authored && ! ($abilities[Ability::Edit->value] ?? false)) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }
    }

    private function isActorAuthor(string $authorType, string $authorId): bool
    {
        $actor = $this->context->actor->ref();

        return ! $this->context->actor->isSystem()
            && $actor->id !== null
            && $actor->type === $authorType
            && Identity::stringify($actor->id) === $authorId;
    }
}
