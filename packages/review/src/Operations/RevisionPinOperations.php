<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Operations;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;
use Vaulter\EditorPro\Review\Data\PinRevisionInput;
use Vaulter\EditorPro\Review\Data\RevisionCompareData;
use Vaulter\EditorPro\Review\Data\RevisionPinData;
use Vaulter\EditorPro\Review\Data\RevisionPreviewData;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;
use Vaulter\EditorPro\Review\Exceptions\RevisionPinNotFoundException;
use Vaulter\EditorPro\Review\Idempotency\ReviewNodeReplayAuthorizer;
use Vaulter\EditorPro\Review\Idempotency\RevisionPinReceiptCodec;
use Vaulter\EditorPro\Review\Repositories\ReviewLookup;
use Vaulter\EditorPro\Review\Repositories\RevisionPinRepository;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Support\Identity;

/**
 * Public operations for immutable revision pins (`v_document_revision_pins`),
 * read-only historical revision preview and structural compare, enforcing current ACL.
 */
#[Api(since: '1.0')]
final readonly class RevisionPinOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private OperationRunner $runner,
        private ReviewLookup $lookup,
        private RevisionPinRepository $pins,
        private ReviewOptions $options,
    ) {}

    /**
     * List active (non-expired by default) revision pins on a document. Requires View.
     *
     * @return list<RevisionPinData>
     */
    public function list(NodeSnapshot|string|int $document, bool $includeExpired = false): array
    {
        $snapshot = $this->drive()->nodes()->find($document);
        $doc = $this->lookup->documentForNode($snapshot->id);

        return $this->pins->listForDocument($doc['id'], $includeExpired);
    }

    public function find(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int $revision): ?RevisionPinData
    {
        $snapshot = $this->drive()->nodes()->find($document);
        $doc = $this->lookup->documentForNode($snapshot->id);
        $revisionId = self::revisionIdOf($revision);

        return $this->pins->findActive($revisionId, $doc['id']);
    }

    /**
     * Pin an immutable revision. An indefinite pin also sets the Documents retention flag
     * when retention enforcement is enabled.
     */
    public function pin(
        NodeSnapshot|string|int $document,
        DocumentRevisionSnapshot|string|int $revision,
        ?PinRevisionInput $input = null,
    ): RevisionPinData {
        $input ??= new PinRevisionInput;
        if ($input->expiresAt !== null && $input->expiresAt <= Carbon::now()->toImmutable()) {
            throw InvalidAnchorSelectorException::because('expires_at must be strictly after pinned_at.', 'invalid_expires_at');
        }
        $nodeId = self::nodeIdOf($document);
        $revisionId = self::revisionIdOf($revision);
        $ability = self::requiredAbilityForReason($input->reason);

        $idempotency = new IdempotentWrite(
            codec: new RevisionPinReceiptCodec($nodeId),
            authorizer: new ReviewNodeReplayAuthorizer($this->context, $this->vaulter, $nodeId, $ability),
            fingerprint: [
                'node' => $nodeId,
                'revision' => $revisionId,
                'reason' => $input->reason->value,
                'expires_at' => $input->expiresAt?->format(DateTimeImmutable::ATOM),
            ],
        );

        /** @var RevisionPinData $result */
        $result = $this->runner->write(
            new OperationSpec('review.pins.pin', new ModuleId('review')),
            $this->context->operation,
            function () use ($document, $revisionId, $input, $ability): RevisionPinData {
                $drive = $this->drive();
                $this->assertActiveDrive($drive);

                $node = $drive->nodes()->find($document);
                $abilities = $drive->nodes()->abilities($node->id)->flags;
                if (! ($abilities[$ability->value] ?? false)) {
                    throw PermissionDeniedException::forAbility($ability->value);
                }

                $docOps = $this->documents->forContext($this->context);
                $docOps->lockForRelatedWrite($node->id);
                $revisionMeta = $docOps->content($node->id, $revisionId)->revision
                    ?? throw RevisionPinNotFoundException::forRevision($node->id, $revisionId);

                $pin = $this->pins->upsert(
                    revisionId: $revisionMeta->id,
                    documentId: $revisionMeta->documentId,
                    tenantId: $this->lookup->tenantForDocument($revisionMeta->documentId),
                    reason: $input->reason,
                    sourceGeneration: $revisionMeta->epoch,
                    sourcePosition: $revisionMeta->stateVersion,
                    pinnedBySubject: $this->actorSubject(),
                    expiresAt: $input->expiresAt,
                );

                if ($this->options->enforceRetention && $input->expiresAt === null && ! $revisionMeta->keepForever) {
                    // The Review permission above also allows commenter-created history pins.
                    $systemDrive = $this->vaulter->findDriveById($this->context->operation->scope->id);
                    if ($systemDrive !== null) {
                        $this->documents->in($systemDrive->asSystem('review.pin_retention'))
                            ->setRetention($node->id, $revisionMeta->id, keepForever: true);
                    }
                }

                return $pin;
            },
            $idempotency,
        );

        return $result;
    }

    /**
     * Unpin a revision and optionally release `keep_forever` on `v_document_revisions`.
     */
    public function unpin(
        NodeSnapshot|string|int $document,
        DocumentRevisionSnapshot|string|int $revision,
        bool $releaseRetention = false,
    ): bool {
        $nodeId = self::nodeIdOf($document);
        $revisionId = self::revisionIdOf($revision);

        $idempotency = new IdempotentWrite(
            codec: new RevisionPinReceiptCodec($nodeId),
            authorizer: new ReviewNodeReplayAuthorizer($this->context, $this->vaulter, $nodeId, Ability::Edit),
            fingerprint: [
                'node' => $nodeId,
                'revision' => $revisionId,
                'release_retention' => $releaseRetention,
            ],
        );

        /** @var bool $result */
        $result = $this->runner->write(
            new OperationSpec('review.pins.unpin', new ModuleId('review')),
            $this->context->operation,
            function () use ($document, $revisionId, $releaseRetention): bool {
                $drive = $this->drive();
                $this->assertActiveDrive($drive);

                $node = $drive->nodes()->find($document);
                $abilities = $drive->nodes()->abilities($node->id)->flags;
                if (! ($abilities[Ability::Edit->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $docOps = $this->documents->forContext($this->context);
                $docOps->lockForRelatedWrite($node->id);
                $docRow = $this->lookup->documentForNode($node->id);
                $deleted = $this->pins->delete($revisionId, $docRow['id']);
                if (! $deleted) {
                    throw RevisionPinNotFoundException::forRevision($node->id, $revisionId);
                }

                if ($releaseRetention) {
                    $docOps->setRetention($node->id, $revisionId, keepForever: false);
                }

                return true;
            },
            $idempotency,
        );

        return $result;
    }

    /**
     * Remove expired pins for a document. Pin protection ends at expiry.
     * The legacy releaseRetention argument is accepted for existing callers.
     */
    public function releaseExpired(NodeSnapshot|string|int $document, bool $releaseRetention = true): int
    {
        $released = $this->runner->write(
            new OperationSpec('review.pins.release_expired', new ModuleId('review')),
            $this->context->operation,
            function () use ($document): int {
                $drive = $this->drive();
                $this->assertActiveDrive($drive);

                $node = $drive->nodes()->find($document);
                $abilities = $drive->nodes()->abilities($node->id)->flags;
                if (! ($abilities[Ability::Edit->value] ?? false)) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $docRow = $this->lookup->documentForNode($node->id);

                return $this->pins->deleteExpired($docRow['id']);
            },
        );

        return $released;
    }

    /**
     * Read-only immutable preview of a historical revision, enforcing the caller's current View/Egress ACL.
     */
    public function preview(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int $revision): RevisionPreviewData
    {
        $revisionId = self::revisionIdOf($revision);
        $snapshot = $this->documents->forContext($this->context)->content($document, $revisionId);
        $revMeta = $snapshot->revision ?? throw RevisionPinNotFoundException::forRevision(self::nodeIdOf($document), $revisionId);

        $pin = $this->pins->findActive($revMeta->id, $snapshot->documentId);

        return new RevisionPreviewData(
            revision: $revMeta,
            content: $snapshot->content,
            pin: $pin,
        );
    }

    /**
     * Read-only two-version compare payload between `$fromRevision` and `$toRevision` (or current working state when null).
     */
    public function compare(
        NodeSnapshot|string|int $document,
        DocumentRevisionSnapshot|string|int $fromRevision,
        DocumentRevisionSnapshot|string|int|null $toRevision = null,
    ): RevisionCompareData {
        $docOps = $this->documents->forContext($this->context);
        $fromId = self::revisionIdOf($fromRevision);
        $fromSnap = $docOps->content($document, $fromId);
        $fromMeta = $fromSnap->revision ?? throw RevisionPinNotFoundException::forRevision(self::nodeIdOf($document), $fromId);

        $toId = $toRevision === null ? null : self::revisionIdOf($toRevision);
        $toSnap = $docOps->content($document, $toId);
        $toMeta = $toId === null ? $toSnap : ($toSnap->revision ?? $toSnap);

        $fromPin = $this->pins->findActive($fromMeta->id, $fromSnap->documentId);
        $toPin = $toMeta instanceof DocumentRevisionSnapshot
            ? $this->pins->findActive($toMeta->id, $toSnap->documentId)
            : ($toSnap->headRevisionId !== null ? $this->pins->findActive($toSnap->headRevisionId, $toSnap->documentId) : null);

        return new RevisionCompareData(
            from: $fromMeta,
            to: $toMeta,
            fromContent: $fromSnap->content,
            toContent: $toSnap->content,
            fromPin: $fromPin,
            toPin: $toPin,
        );
    }

    private static function requiredAbilityForReason(PinReason $reason): Ability
    {
        return match ($reason) {
            PinReason::ManualVersion, PinReason::ExchangeSource => Ability::Edit,
            PinReason::ReviewBase, PinReason::CommentHistory => Ability::Comment,
        };
    }

    private function assertActiveDrive(DriveHandle $drive): void
    {
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }
    }

    private function drive(): DriveHandle
    {
        return ReviewDriveHandle::fromContext($this->vaulter, $this->context, withIdempotency: true);
    }

    private function actorSubject(): string
    {
        $ref = $this->context->actor->ref();
        if ($this->context->actor->isSystem()) {
            return substr('system:'.($ref->reason ?? 'review'), 0, 255);
        }

        return substr(($ref->type ?? 'user').':'.Identity::stringify($ref->id), 0, 255);
    }

    private static function nodeIdOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private static function revisionIdOf(DocumentRevisionSnapshot|DocumentSnapshot|string|int $revision): string
    {
        if ($revision instanceof DocumentRevisionSnapshot) {
            return $revision->id;
        }
        if ($revision instanceof DocumentSnapshot) {
            return $revision->documentId;
        }

        return (string) $revision;
    }
}
