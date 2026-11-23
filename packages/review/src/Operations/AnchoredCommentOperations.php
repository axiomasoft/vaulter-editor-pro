<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Operations;

use Illuminate\Support\Carbon;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Anchors\SelectorValidator;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Data\AddAnchoredCommentInput;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Data\CommentAnchorInput;
use Vaulter\EditorPro\Review\Data\PinRevisionInput;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\CommentFilterStatus;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\EditorPro\Review\Exceptions\InvalidCommentStateException;
use Vaulter\EditorPro\Review\Idempotency\AnchoredCommentReceiptCodec;
use Vaulter\EditorPro\Review\Idempotency\ReviewCommentReplayAuthorizer;
use Vaulter\EditorPro\Review\Idempotency\RevisionPinReceiptCodec;
use Vaulter\EditorPro\Review\Repositories\CommentAnchorRepository;
use Vaulter\EditorPro\Review\Repositories\ReviewLookup;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\EditorPro\Review\Support\ReviewTags;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Support\Identity;

/**
 * Public operations extending Vaulter `v_node_comments` with generation-scoped range
 * anchors (`v_document_comment_anchors`), CAS reopen/resolve, reanchor, and snapshot
 * reconciliation while preserving full backwards compatibility with plain node comments.
 */
#[Api(since: '1.0')]
final readonly class AnchoredCommentOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private DocumentsOptions $documentsOptions,
        private OperationRunner $runner,
        private ReviewLookup $lookup,
        private CommentAnchorRepository $anchors,
        private SelectorValidator $selectorValidator,
        private SnapshotAnchorResolver $snapshotResolver,
    ) {}

    /**
     * List comments (merged with any range anchors), optionally filtered by thread status.
     * Preserves plain node comments (`anchor === null`) alongside range-anchored discussions.
     *
     * @return Page<AnchoredCommentData>
     */
    public function list(
        NodeSnapshot|string|int $node,
        CommentFilterStatus $filter = CommentFilterStatus::All,
        ?ListOptions $options = null,
    ): Page {
        $drive = $this->drive();
        $documentComments = $this->documents->comments($drive);
        $commentPage = $documentComments->list($node, $options);
        $comments = $commentPage->items;

        $ids = array_map(static fn (CommentSnapshot $c): string => $c->id, $comments);
        $roots = $filter === CommentFilterStatus::All ? [] : $this->threadRoots($documentComments, $node, $comments);
        foreach ($roots as $root) {
            $ids[] = $root->id;
        }
        $anchorsByComment = $this->anchors->mapByCommentIds(array_values(array_unique($ids)));

        $merged = array_map(
            static fn (CommentSnapshot $c): AnchoredCommentData => new AnchoredCommentData(
                comment: $c,
                anchor: $anchorsByComment[$c->id] ?? null,
                etag: self::commentTag($c),
            ),
            $comments,
        );

        if ($filter === CommentFilterStatus::All) {
            return new Page(items: $merged, nextCursor: $commentPage->nextCursor);
        }

        $filtered = array_values(array_filter(
            $merged,
            static function (AnchoredCommentData $item) use ($filter, $roots, $anchorsByComment): bool {
                $root = $roots[$item->comment->id] ?? null;
                $subject = $root === null ? $item : new AnchoredCommentData(
                    comment: $root,
                    anchor: $anchorsByComment[$root->id] ?? null,
                    etag: self::commentTag($root),
                );

                return match ($filter) {
                    CommentFilterStatus::Resolved => $subject->isResolved(),
                    CommentFilterStatus::Detached => ! $subject->isResolved() && $subject->isDetached(),
                    CommentFilterStatus::Active => $subject->isActive(),
                };
            },
        ));

        return new Page(items: $filtered, nextCursor: $commentPage->nextCursor);
    }

    /**
     * @param  list<CommentSnapshot>  $comments
     * @return array<string, CommentSnapshot> Thread roots keyed by reply ID
     */
    private function threadRoots(CommentOperations $documents, NodeSnapshot|string|int $node, array $comments): array
    {
        $known = [];
        $replies = [];
        foreach ($comments as $comment) {
            $known[$comment->id] = $comment;
            if ($comment->parentId !== null) {
                $replies[$comment->id] = $comment->parentId;
            }
        }

        $roots = [];
        $cursor = null;
        $hasMore = true;
        while ($replies !== []) {
            foreach ($replies as $replyId => $parentId) {
                while (isset($known[$parentId])) {
                    $parent = $known[$parentId];
                    if ($parent->parentId === null) {
                        $roots[$replyId] = $parent;
                        unset($replies[$replyId]);
                        break;
                    }

                    $parentId = $parent->parentId;
                    $replies[$replyId] = $parentId;
                }
            }

            if ($replies === [] || ! $hasMore) {
                break;
            }

            // Documents exposes the scoped comment list as a cursor feed.
            $page = $documents->list($node, new ListOptions(perPage: 200, cursor: $cursor));
            foreach ($page->items as $comment) {
                $known[$comment->id] = $comment;
            }
            $cursor = $page->nextCursor;
            $hasMore = $cursor !== null;
        }

        return $roots;
    }

    public function find(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment): AnchoredCommentData
    {
        $commentId = self::commentIdOf($comment);
        $page = $this->list($node, CommentFilterStatus::All, new ListOptions(perPage: 100));
        foreach ($page->items as $item) {
            if ($item->comment->id === $commentId) {
                return $item;
            }
        }

        $cursor = $page->nextCursor;
        while ($cursor !== null) {
            $nextPage = $this->list($node, CommentFilterStatus::All, new ListOptions(cursor: $cursor, perPage: 100));
            foreach ($nextPage->items as $item) {
                if ($item->comment->id === $commentId) {
                    return $item;
                }
            }
            $cursor = $nextPage->nextCursor;
        }

        throw CommentNotFoundException::forNode(self::nodeIdOf($node), $commentId);
    }

    /**
     * Add a root comment (plain or range-anchored) or a threaded reply.
     * Author identity always comes from `$context->actor` (client payload cannot spoof authorship).
     */
    public function add(NodeSnapshot|string|int $node, AddAnchoredCommentInput $input): AnchoredCommentData
    {
        if ($input->parentId !== null && $input->anchor !== null) {
            throw InvalidCommentStateException::replyCannotCarryRangeAnchor();
        }

        if ($input->anchor === null) {
            $drive = $this->drive();
            $created = $this->documents->comments($drive)->add(
                $node,
                new AddCommentInput(
                    body: $input->body,
                    parentId: $input->parentId,
                ),
            );

            return new AnchoredCommentData($created, null, self::commentTag($created));
        }

        $nodeId = self::nodeIdOf($node);
        $anchorInput = $input->anchor;
        $normalizedPayload = $this->selectorValidator->validateAndNormalize($anchorInput);

        $idempotency = new IdempotentWrite(
            codec: new AnchoredCommentReceiptCodec,
            authorizer: new ReviewCommentReplayAuthorizer(
                context: $this->context,
                vaulter: $this->vaulter,
                documentsOptions: $this->documentsOptions,
                verb: ReviewCommentReplayAuthorizer::VERB_ADD,
                nodeId: $nodeId,
            ),
            fingerprint: [
                'node' => $nodeId,
                'body' => $input->body,
                'parent_id' => $input->parentId,
                'anchor' => [
                    'generation' => $anchorInput->generation,
                    'source_position' => $anchorInput->sourcePosition,
                    'schema_version' => $anchorInput->schemaVersion,
                    'block_id' => $anchorInput->blockId,
                    'selector_kind' => $anchorInput->selectorKind->value,
                    'selector_payload' => $normalizedPayload,
                    'quote_text' => $anchorInput->quoteText,
                    'pin_revision_id' => $anchorInput->pinRevisionId,
                ],
            ],
        );

        /** @var AnchoredCommentData $result */
        $result = $this->runner->write(
            new OperationSpec('review.comments.add', new ModuleId('review')),
            $this->context->operation,
            function () use ($node, $input, $anchorInput, $normalizedPayload): AnchoredCommentData {
                $drive = $this->driveWithoutIdempotency();
                $nodeSnapshot = $drive->nodes()->find($node);
                $doc = $this->lookup->documentForNode($nodeSnapshot->id);

                if ($anchorInput->generation !== $doc['collab_epoch']) {
                    throw InvalidCommentStateException::staleGeneration($anchorInput->generation, $doc['collab_epoch']);
                }

                $legacyAnchorSummary = [
                    'kind' => $anchorInput->selectorKind->value,
                    'block_id' => $anchorInput->blockId,
                    'generation' => $anchorInput->generation,
                ];

                $comment = $this->documents->comments($drive)->add(
                    $nodeSnapshot->id,
                    new AddCommentInput(
                        body: $input->body,
                        parentId: null,
                        anchor: $legacyAnchorSummary,
                    ),
                );

                // Documents holds the node lock until this operation commits. If a restore won
                // that lock before the add, the refusal rolls back the delegated comment as well.
                $doc = $this->lookup->documentForNode($nodeSnapshot->id);
                if ($anchorInput->generation !== $doc['collab_epoch']) {
                    throw InvalidCommentStateException::staleGeneration($anchorInput->generation, $doc['collab_epoch']);
                }

                $anchorData = $this->anchors->create(
                    tenantId: $doc['tenant_id'],
                    commentId: $comment->id,
                    documentId: $doc['id'],
                    generation: $anchorInput->generation,
                    sourcePosition: $anchorInput->sourcePosition,
                    schemaVersion: $anchorInput->schemaVersion,
                    blockId: $anchorInput->blockId,
                    selectorKind: $anchorInput->selectorKind,
                    selectorPayload: $normalizedPayload,
                    quoteText: $anchorInput->quoteText,
                    anchorState: AnchorState::Attached,
                );

                if ($anchorInput->pinRevisionId !== null && $anchorInput->pinRevisionId !== '') {
                    $drive->extension(RevisionPinOperations::class)->pin(
                        $nodeSnapshot->id,
                        $anchorInput->pinRevisionId,
                        new PinRevisionInput(PinReason::CommentHistory),
                    );
                }

                return new AnchoredCommentData(
                    comment: $comment,
                    anchor: $anchorData,
                    etag: self::commentTag($comment),
                );
            },
            $idempotency,
        );

        return $result;
    }

    /** Resolve a comment thread with CAS on the comment version. */
    public function resolve(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment, ?int $expectedVersion = null): AnchoredCommentData
    {
        $nodeId = self::nodeIdOf($node);
        $commentId = self::commentIdOf($comment);
        $authorizer = new ReviewCommentReplayAuthorizer(
            context: $this->context,
            vaulter: $this->vaulter,
            documentsOptions: $this->documentsOptions,
            verb: ReviewCommentReplayAuthorizer::VERB_REOPEN,
            nodeId: $nodeId,
        );

        $idempotency = new IdempotentWrite(
            codec: new AnchoredCommentReceiptCodec,
            authorizer: $authorizer,
            fingerprint: [
                'node' => $nodeId,
                'comment' => $commentId,
                'expected_version' => $expectedVersion,
            ],
        );

        /** @var AnchoredCommentData $result */
        $result = $this->runner->write(
            new OperationSpec('review.comments.resolve', new ModuleId('review')),
            $this->context->operation,
            function () use ($node, $comment, $expectedVersion, $authorizer): AnchoredCommentData {
                $drive = $this->driveWithoutIdempotency();
                $resolved = $this->documents->comments($drive)->resolve($node, $comment, $expectedVersion);
                $actor = $this->context->actor->ref();
                if (! $this->context->actor->isSystem() && $actor->type === $resolved->authorType && Identity::stringify($actor->id) === $resolved->authorId) {
                    $authorizer->provedByAuthorship();
                }
                $anchor = $this->anchors->findByComment($resolved->id);

                return new AnchoredCommentData($resolved, $anchor, self::commentTag($resolved));
            },
            $idempotency,
        );

        return $result;
    }

    /**
     * Reopen a resolved comment thread with strict CAS (`v_node_comments.version`),
     * clearing `resolved_at`/`resolved_by` and incrementing `version`.
     */
    public function reopen(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment, ?int $expectedVersion = null): AnchoredCommentData
    {
        $actorRef = $this->context->actor->ref();
        if ($this->context->actor->isSystem() || $actorRef->type === null || $actorRef->id === null) {
            throw MissingActorException::required();
        }

        $nodeId = self::nodeIdOf($node);
        $commentId = self::commentIdOf($comment);
        $authorizer = new ReviewCommentReplayAuthorizer(
            context: $this->context,
            vaulter: $this->vaulter,
            documentsOptions: $this->documentsOptions,
            verb: ReviewCommentReplayAuthorizer::VERB_REOPEN,
            nodeId: $nodeId,
        );

        $idempotency = new IdempotentWrite(
            codec: new AnchoredCommentReceiptCodec,
            authorizer: $authorizer,
            fingerprint: [
                'node' => $nodeId,
                'comment' => $commentId,
                'expected_version' => $expectedVersion,
            ],
        );

        /** @var AnchoredCommentData $result */
        $result = $this->runner->write(
            new OperationSpec('review.comments.reopen', new ModuleId('review')),
            $this->context->operation,
            function () use ($node, $commentId, $expectedVersion, $authorizer, $actorRef): AnchoredCommentData {
                $drive = $this->driveWithoutIdempotency();
                $this->assertMutableCommentsContext($drive);

                $nodeSnapshot = $drive->nodes()->find($node);
                $abilities = $drive->nodes()->abilities($nodeSnapshot->id)->flags;
                if (! ($abilities[Ability::Comment->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Comment->value);
                }

                $row = $this->lookup->lockCommentRow($nodeSnapshot->id, $commentId);
                $rowAuthorType = is_string($row->author_type ?? null) ? $row->author_type : '';
                $rowAuthorId = Identity::stringify($row->author_id ?? null);
                $isAuthor = $rowAuthorType === $actorRef->type && $rowAuthorId === Identity::stringify($actorRef->id);

                if (! $isAuthor && ! ($abilities[Ability::Edit->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                if ($isAuthor) {
                    $authorizer->provedByAuthorship();
                }

                $currentVersion = is_numeric($row->version ?? null) ? (int) $row->version : 0;
                if ($expectedVersion !== null && $currentVersion !== $expectedVersion) {
                    throw StaleWriteException::of('documents.comment', $commentId);
                }

                $now = Carbon::now();
                $nextVersion = $currentVersion + 1;
                $updatedRows = $this->lookup->connection()
                    ->table($this->lookup->table('node_comments'))
                    ->where('id', $commentId)
                    ->where('node_id', $nodeSnapshot->id)
                    ->where('version', $currentVersion)
                    ->update([
                        'resolved_at' => null,
                        'resolved_by' => null,
                        'version' => $nextVersion,
                        'updated_at' => $now->toDateTimeString(),
                    ]);

                if ($updatedRows !== 1) {
                    throw StaleWriteException::of('documents.comment', $commentId);
                }

                $reopened = $this->documents->comments($drive)->list($nodeSnapshot->id, new ListOptions(perPage: 100));
                $commentSnapshot = null;
                foreach ($reopened->items as $candidate) {
                    if ($candidate->id === $commentId) {
                        $commentSnapshot = $candidate;
                        break;
                    }
                }
                if ($commentSnapshot === null) {
                    $commentSnapshot = $this->find($nodeSnapshot->id, $commentId)->comment;
                }

                $anchor = $this->anchors->findByComment($commentId);

                return new AnchoredCommentData($commentSnapshot, $anchor, self::commentTag($commentSnapshot));
            },
            $idempotency,
        );

        return $result;
    }

    /**
     * Reattach or move a root comment's anchor to a new valid range in the current document generation,
     * with CAS check on `v_document_comment_anchors.version` when an anchor row already exists.
     */
    public function reanchor(
        NodeSnapshot|string|int $node,
        CommentSnapshot|string|int $comment,
        CommentAnchorInput $input,
        ?int $expectedAnchorVersion = null,
    ): AnchoredCommentData {
        $actorRef = $this->context->actor->ref();
        if ($this->context->actor->isSystem() || $actorRef->type === null || $actorRef->id === null) {
            throw MissingActorException::required();
        }

        $nodeId = self::nodeIdOf($node);
        $commentId = self::commentIdOf($comment);
        $normalizedPayload = $this->selectorValidator->validateAndNormalize($input);

        $authorizer = new ReviewCommentReplayAuthorizer(
            context: $this->context,
            vaulter: $this->vaulter,
            documentsOptions: $this->documentsOptions,
            verb: ReviewCommentReplayAuthorizer::VERB_REANCHOR,
            nodeId: $nodeId,
        );

        $idempotency = new IdempotentWrite(
            codec: new AnchoredCommentReceiptCodec,
            authorizer: $authorizer,
            fingerprint: [
                'node' => $nodeId,
                'comment' => $commentId,
                'expected_anchor_version' => $expectedAnchorVersion,
                'generation' => $input->generation,
                'source_position' => $input->sourcePosition,
                'schema_version' => $input->schemaVersion,
                'block_id' => $input->blockId,
                'selector_kind' => $input->selectorKind->value,
                'selector_payload' => $normalizedPayload,
                'quote_text' => $input->quoteText,
                'pin_revision_id' => $input->pinRevisionId,
            ],
        );

        /** @var AnchoredCommentData $result */
        $result = $this->runner->write(
            new OperationSpec('review.comments.reanchor', new ModuleId('review')),
            $this->context->operation,
            function () use ($node, $commentId, $input, $normalizedPayload, $expectedAnchorVersion, $authorizer, $actorRef): AnchoredCommentData {
                $drive = $this->driveWithoutIdempotency();
                $this->assertMutableCommentsContext($drive);

                $nodeSnapshot = $drive->nodes()->find($node);
                $abilities = $drive->nodes()->abilities($nodeSnapshot->id)->flags;
                if (! ($abilities[Ability::Comment->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Comment->value);
                }

                $row = $this->lookup->lockCommentRow($nodeSnapshot->id, $commentId);
                if ($row->parent_id !== null) {
                    throw InvalidCommentStateException::replyCannotCarryRangeAnchor();
                }

                $isAuthor = is_string($row->author_type ?? null)
                    && $row->author_type === $actorRef->type
                    && Identity::stringify($row->author_id ?? null) === Identity::stringify($actorRef->id);

                if (! $isAuthor && ! ($abilities[Ability::Edit->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                if ($isAuthor) {
                    $authorizer->provedByAuthorship();
                }

                $doc = $this->lookup->documentForNode($nodeSnapshot->id);
                if ($input->generation !== $doc['collab_epoch']) {
                    throw InvalidCommentStateException::staleGeneration($input->generation, $doc['collab_epoch']);
                }

                $existingAnchor = $this->anchors->findByComment($commentId);
                if ($existingAnchor === null) {
                    $commentVersion = is_numeric($row->version ?? null) ? (int) $row->version : 0;
                    if ($expectedAnchorVersion !== null && $expectedAnchorVersion !== $commentVersion) {
                        throw StaleWriteException::of('documents.comment', $commentId);
                    }

                    $anchor = $this->anchors->create(
                        tenantId: $doc['tenant_id'],
                        commentId: $commentId,
                        documentId: $doc['id'],
                        generation: $input->generation,
                        sourcePosition: $input->sourcePosition,
                        schemaVersion: $input->schemaVersion,
                        blockId: $input->blockId,
                        selectorKind: $input->selectorKind,
                        selectorPayload: $normalizedPayload,
                        quoteText: $input->quoteText,
                        anchorState: AnchorState::Attached,
                    );
                } else {
                    $anchor = $this->anchors->updateWithCas(
                        commentId: $commentId,
                        documentId: $doc['id'],
                        generation: $input->generation,
                        sourcePosition: $input->sourcePosition,
                        schemaVersion: $input->schemaVersion,
                        blockId: $input->blockId,
                        selectorKind: $input->selectorKind,
                        selectorPayload: $normalizedPayload,
                        quoteText: $input->quoteText,
                        anchorState: AnchorState::Attached,
                        expectedVersion: $expectedAnchorVersion,
                    );
                }

                if ($input->pinRevisionId !== null && $input->pinRevisionId !== '') {
                    $drive->extension(RevisionPinOperations::class)->pin(
                        $nodeSnapshot->id,
                        $input->pinRevisionId,
                        new PinRevisionInput(PinReason::CommentHistory),
                    );
                }

                $commentSnapshot = $this->find($nodeSnapshot->id, $commentId)->comment;

                return new AnchoredCommentData($commentSnapshot, $anchor, self::commentTag($commentSnapshot));
            },
            $idempotency,
        );

        return $result;
    }

    /**
     * Reconcile attached `snapshot_utf16` anchors of the current generation after a snapshot edit
     * (`$oldDoc -> $newDoc` when both supplied, else stateless remap).
     *
     * @param  array<string, mixed>  $newDoc
     * @param  array<string, mixed>|null  $oldDoc
     * @return list<CommentAnchorData> updated anchors
     */
    public function reconcileSnapshot(
        NodeSnapshot|string|int $node,
        array $newDoc,
        ?array $oldDoc = null,
        ?int $generation = null,
    ): array {
        /** @var list<CommentAnchorData> $updated */
        $updated = $this->runner->write(
            new OperationSpec('review.comments.reconcile_snapshot', new ModuleId('review')),
            $this->context->operation,
            function () use ($node, $newDoc, $oldDoc, $generation): array {
                $drive = $this->driveWithoutIdempotency();
                $this->assertMutableCommentsContext($drive);

                $nodeSnapshot = $drive->nodes()->find($node);
                $abilities = $drive->nodes()->abilities($nodeSnapshot->id)->flags;
                if (! ($abilities[Ability::Edit->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $doc = $this->lookup->documentForNode($nodeSnapshot->id);
                $targetGeneration = $generation ?? $doc['collab_epoch'];

                $results = [];
                foreach ($this->anchors->listAttachedForDocument($doc['id'], $targetGeneration) as $anchor) {
                    $resolution = $oldDoc !== null
                        ? $this->snapshotResolver->resolveOnEdit($anchor, $oldDoc, $newDoc)
                        : $this->snapshotResolver->resolveStateless($anchor, $newDoc);

                    if (! $resolution->changed) {
                        continue;
                    }

                    $results[] = $this->anchors->updateWithCas(
                        commentId: $anchor->commentId,
                        documentId: $doc['id'],
                        generation: $anchor->generation,
                        sourcePosition: $anchor->sourcePosition,
                        schemaVersion: $anchor->schemaVersion,
                        blockId: $anchor->blockId,
                        selectorKind: $anchor->selectorKind,
                        selectorPayload: $resolution->selectorPayload,
                        quoteText: $resolution->quoteText,
                        anchorState: $resolution->state,
                        expectedVersion: $anchor->version,
                    );
                }

                return $results;
            },
        );

        return $updated;
    }

    /** Delete a comment thread (cascade deletes any range anchor row via FK). */
    public function delete(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment, ?int $expectedVersion = null): void
    {
        // Note: deleting delegates to documents' idempotent comment deletion when not wrapped,
        // or runs cleanly under `review.comments.delete` for HTTP idempotency tracking.
        $nodeId = self::nodeIdOf($node);
        $commentId = self::commentIdOf($comment);
        if ($this->context->operation->idempotencyKey === null) {
            $this->documents->comments($this->drive())->delete($node, $comment, $expectedVersion);

            return;
        }

        $authorizer = new ReviewCommentReplayAuthorizer(
            context: $this->context,
            vaulter: $this->vaulter,
            documentsOptions: $this->documentsOptions,
            verb: ReviewCommentReplayAuthorizer::VERB_ADD,
            nodeId: $nodeId,
        );

        $idempotency = new IdempotentWrite(
            codec: new RevisionPinReceiptCodec($nodeId),
            authorizer: $authorizer,
            fingerprint: [
                'node' => $nodeId,
                'comment' => $commentId,
                'expected_version' => $expectedVersion,
            ],
        );

        $this->runner->write(
            new OperationSpec('review.comments.delete', new ModuleId('review')),
            $this->context->operation,
            function () use ($node, $comment, $expectedVersion): bool {
                $this->documents->comments($this->driveWithoutIdempotency())->delete($node, $comment, $expectedVersion);

                return true;
            },
            $idempotency,
        );
    }

    public static function commentTag(CommentSnapshot $comment): string
    {
        return ReviewTags::comment($comment->id, $comment->version);
    }

    private function assertMutableCommentsContext(DriveHandle $drive): void
    {
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }

        if (! $this->documentsOptions->comments->enabled || ! $drive->profile()->commentsEnabled()) {
            throw ProfileRestrictionException::forOperation('comments');
        }
    }

    private function drive(): DriveHandle
    {
        return ReviewDriveHandle::fromContext($this->vaulter, $this->context, withIdempotency: true);
    }

    private function driveWithoutIdempotency(): DriveHandle
    {
        return ReviewDriveHandle::fromContext($this->vaulter, $this->context, withIdempotency: false);
    }

    private static function nodeIdOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private static function commentIdOf(CommentSnapshot|string|int $comment): string
    {
        return $comment instanceof CommentSnapshot ? $comment->id : (string) $comment;
    }
}
