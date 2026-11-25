<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Operations;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Data\ReviewCapabilitiesData;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;
use Vaulter\EditorPro\Review\Exceptions\JournalProjectionPending;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\EditorPro\Review\Idempotency\ReviewNodeReplayAuthorizer;
use Vaulter\EditorPro\Review\Idempotency\SuggestionReceiptCodec;
use Vaulter\EditorPro\Review\Repositories\ReviewLookup;
use Vaulter\EditorPro\Review\Repositories\SuggestionRepository;
use Vaulter\EditorPro\Review\Suggestions\ReviewCapabilities;
use Vaulter\EditorPro\Review\Suggestions\SnapshotStructuralEdit;
use Vaulter\EditorPro\Review\Suggestions\SnapshotTextEdit;
use Vaulter\EditorPro\Review\Suggestions\SuggestionJournalBridge;
use Vaulter\EditorPro\Review\Suggestions\SuggestionOverlapDetector;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

/**
 * Versioned text and structural review suggestions across both `snapshot` (`AtomicDocumentMutation`)
 * and strict `journal` (`CommandJournal`) document write modes.
 */
#[Api(since: '1.0')]
final readonly class SuggestionOperations implements DriveExtension
{
    /**
     * Open suggestions one document generation may hold. Every save reads and locks all of them and the
     * overlap checks compare them pairwise, so the bound keeps a save (and the editor's full list) cheap.
     */
    public const int MAX_OPEN_PER_DOCUMENT = 1000;

    private ReviewCapabilities $capabilitiesResolver;

    private SnapshotStructuralEdit $structuralEditor;

    private SuggestionOverlapDetector $overlapDetector;

    private SuggestionJournalBridge $journalBridge;

    #[Internal]
    public function __construct(
        private DriveContext $context,
        private SuggestionRepository $suggestions,
        private ReviewLookup $lookup,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private CollaborativeState $collaboration,
        private DocumentTypeRegistry $types,
        private VaulterDatabase $database,
        private OperationRunner $runner,
        Container $container,
    ) {
        $this->capabilitiesResolver = new ReviewCapabilities;
        $this->structuralEditor = new SnapshotStructuralEdit($this->capabilitiesResolver);
        $this->overlapDetector = new SuggestionOverlapDetector;
        $this->journalBridge = new SuggestionJournalBridge($container);
    }

    public function capabilities(int|string|null $nodeId = null): ReviewCapabilitiesData
    {
        if ($nodeId === null) {
            return $this->capabilitiesResolver->build(journalSupported: $this->journalBridge->isAvailable());
        }

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $nodeSnap = $drive->nodes()->find($nodeId);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::View->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }
        $docMeta = $this->documents->in($drive)->metadata($nodeSnap);

        return $this->capabilitiesResolver->build(
            documentType: $docMeta->type,
            writeMode: $docMeta->writeMode,
            schemaVersion: $docMeta->schemaVersion,
            journalSupported: $this->journalBridge->isAvailable(),
        );
    }

    /** @return list<TextSuggestion> */
    public function list(int|string $nodeId, ?string $proposalId = null, bool $includeStaleGenerations = false): array
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $nodeSnap = $drive->nodes()->find($nodeId);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::View->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }
        $docMeta = $this->documents->in($drive)->metadata($nodeSnap);
        $documentId = $docMeta->documentId;
        $generation = $docMeta->generation;

        if ($docMeta->writeMode === 'journal' && $this->journalBridge->isAvailable()) {
            $this->syncPendingJournalState($nodeSnap->id, $documentId, $generation);
        }

        $query = $this->suggestions->forDocument($this->lookup->connection(), $documentId, $generation);
        if ($proposalId !== null) {
            $this->assertUuid($proposalId);
            $query->where('proposal_id', $proposalId);
        }

        $current = $this->suggestions->read($query);
        if (! $includeStaleGenerations || $generation <= 0) {
            return $current;
        }

        $staleQuery = $this->suggestions->query($this->lookup->connection())
            ->where('document_id', $documentId)
            ->where('generation', '<', $generation)
            ->where('state', 'open')
            ->orderBy('generation');
        if ($proposalId !== null) {
            $staleQuery->where('proposal_id', $proposalId);
        }

        $stale = array_map(
            static fn (TextSuggestion $s): TextSuggestion => new TextSuggestion(
                id: $s->id,
                documentId: $s->documentId,
                proposalId: $s->proposalId,
                groupId: $s->groupId,
                parentId: $s->parentId,
                generation: $s->generation,
                basePosition: $s->basePosition,
                schemaVersion: $s->schemaVersion,
                authorSubject: $s->authorSubject,
                kind: $s->kind,
                targetBlockId: $s->targetBlockId,
                from: $s->from,
                to: $s->to,
                before: $s->before,
                after: $s->after,
                state: $s->state,
                conflicted: $s->conflicted,
                version: $s->version,
                receiptId: $s->receiptId,
                decidedBySubject: $s->decidedBySubject,
                decidedAt: $s->decidedAt,
                targetSelector: $s->targetSelector,
                payload: $s->payload,
                conflictReason: 'generation_changed',
                conflictWithId: $s->conflictWithId,
                journalCommandId: $s->journalCommandId,
                journalState: $s->journalState,
                committedPosition: $s->committedPosition,
                generationChanged: true,
            ),
            $this->suggestions->read($staleQuery),
        );

        return [...$current, ...$stale];
    }

    public function find(int|string $nodeId, string $id): TextSuggestion
    {
        $this->assertUuid($id);
        foreach ($this->list($nodeId) as $suggestion) {
            if ($suggestion->id === $id) {
                return $suggestion;
            }
        }

        throw SuggestionConflictException::because('The requested suggestion was not found.', 'suggestion_not_found');
    }

    /**
     * Create with version 0, or amend/retarget an open suggestion as its original author.
     *
     * @param  list<string>|null  $ifMatch
     */
    public function save(int|string $nodeId, TextSuggestionInput|StructuralSuggestionInput $input, ?int $expectedVersion = 0, ?array $ifMatch = null): TextSuggestion
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        if ($drive->snapshot()->status !== DriveStatus::Active) {
            throw DriveArchivedException::forDrive($drive->snapshot()->id);
        }

        $nodeSnap = $drive->nodes()->find($nodeId);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::Edit->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }
        $docMeta = $this->documents->in($drive)->metadata($nodeSnap);

        if ($docMeta->writeMode === 'journal') {
            return $this->saveViaJournal($nodeSnap->id, $input, $expectedVersion, $ifMatch);
        }

        return $this->writeSnapshot($this->saveSpec(), $nodeSnap->id, $this->saveFingerprint($input, $expectedVersion, $ifMatch), function (LockedDocumentMutation $locked) use ($input, $expectedVersion, $ifMatch): TextSuggestion {
            $state = $locked->snapshot();
            $this->assertSnapshot($state);
            $this->capabilitiesResolver->assertDocumentSupported($state->type, $state->schemaVersion);
            $this->capabilitiesResolver->assertInputSupported($input);

            if ($input->generation !== $state->generation || $input->basePosition !== $state->position || $input->schemaVersion !== $state->schemaVersion) {
                throw new SuggestionConflictException('The suggestion base is stale.');
            }

            $author = $this->subject();
            $conn = $locked->connection();
            $query = $this->suggestions->forDocument($conn, $state->documentId, $state->generation)->lockForUpdate();
            $rows = array_column($this->suggestions->read($query), null, 'id');
            $current = $rows[$input->id] ?? null;
            $this->assertSaveAmendable($current, $input, $this->saveVersion($current, $expectedVersion, $ifMatch), $author);

            $doc = $state->content->toArray();
            $this->validateDraft($input, $doc, $rows);

            return $this->persistDraft($conn, $query, $state->documentId, $state->generation, $state->position, $state->schemaVersion, $author, $input, $current, $rows, $doc);
        });
    }

    /** @param list<string>|null $ifMatch */
    public function accept(int|string $nodeId, string $id, int $generation, ?int $expectedVersion, ?string $commandId = null, ?array $ifMatch = null): TextSuggestion
    {
        return $this->decide($nodeId, $generation, [$id => $expectedVersion ?? 0], 'accepted', commandId: $commandId, ifMatch: $ifMatch)[0];
    }

    /** @param list<string>|null $ifMatch */
    public function reject(int|string $nodeId, string $id, int $generation, ?int $expectedVersion, ?string $commandId = null, ?array $ifMatch = null): TextSuggestion
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $nodeSnap = $drive->nodes()->find($nodeId);
        $docMeta = $this->documents->in($drive)->metadata($nodeSnap);
        if ($generation < $docMeta->generation) {
            return $this->rejectStaleGeneration($nodeSnap->id, $docMeta->documentId, $id, $generation, $expectedVersion ?? 0);
        }

        return $this->decide($nodeId, $generation, [$id => $expectedVersion ?? 0], 'rejected', commandId: $commandId, ifMatch: $ifMatch)[0];
    }

    /**
     * Bulk accept all open, non-proposal suggestions in the current generation.
     *
     * @return list<TextSuggestion>
     */
    public function acceptAll(int|string $nodeId, int $generation, ?string $commandId = null): array
    {
        return $this->decideBulk($nodeId, $generation, 'accepted', commandId: $commandId);
    }

    /**
     * Bulk reject all open, non-proposal suggestions in the current generation.
     *
     * @return list<TextSuggestion>
     */
    public function rejectAll(int|string $nodeId, int $generation, ?string $commandId = null): array
    {
        return $this->decideBulk($nodeId, $generation, 'rejected', commandId: $commandId);
    }

    /**
     * Bulk accept open, non-proposal suggestions authored by a specific subject.
     *
     * @return list<TextSuggestion>
     */
    public function acceptByUser(int|string $nodeId, int $generation, string $authorSubject, ?string $commandId = null): array
    {
        return $this->decideBulk($nodeId, $generation, 'accepted', authorSubject: $authorSubject, commandId: $commandId);
    }

    /**
     * Bulk reject open, non-proposal suggestions authored by a specific subject.
     *
     * @return list<TextSuggestion>
     */
    public function rejectByUser(int|string $nodeId, int $generation, string $authorSubject, ?string $commandId = null): array
    {
        return $this->decideBulk($nodeId, $generation, 'rejected', authorSubject: $authorSubject, commandId: $commandId);
    }

    /**
     * Bulk accept open, non-proposal suggestions overlapping `[from, to]`.
     *
     * @return list<TextSuggestion>
     */
    public function acceptInRange(int|string $nodeId, int $generation, int $from, int $to, ?string $commandId = null): array
    {
        return $this->decideBulk($nodeId, $generation, 'accepted', from: $from, to: $to, commandId: $commandId);
    }

    /**
     * Bulk reject open, non-proposal suggestions overlapping `[from, to]`.
     *
     * @return list<TextSuggestion>
     */
    public function rejectInRange(int|string $nodeId, int $generation, int $from, int $to, ?string $commandId = null): array
    {
        return $this->decideBulk($nodeId, $generation, 'rejected', from: $from, to: $to, commandId: $commandId);
    }

    /**
     * @param  'accepted'|'rejected'  $decision
     * @return list<TextSuggestion>
     */
    private function decideBulk(
        int|string $nodeId,
        int $generation,
        string $decision,
        ?string $authorSubject = null,
        ?int $from = null,
        ?int $to = null,
        ?string $commandId = null,
    ): array {
        $candidates = array_values(array_filter(
            $this->list($nodeId),
            static function (TextSuggestion $s) use ($generation, $authorSubject, $from, $to): bool {
                if ($s->generation !== $generation || $s->state !== 'open' || $s->isPendingJournal() || $s->proposalId !== null) {
                    return false;
                }
                if ($authorSubject !== null && $s->authorSubject !== $authorSubject) {
                    return false;
                }
                if ($from !== null && $to !== null && ($s->to < $from || $s->from > $to)) {
                    return false;
                }

                return true;
            },
        ));

        if ($candidates === []) {
            return [];
        }

        $expectedVersions = [];
        foreach ($candidates as $candidate) {
            $expectedVersions[$candidate->id] = $candidate->version;
        }

        return $this->decide($nodeId, $generation, $expectedVersions, $decision, commandId: $commandId);
    }

    private function rejectStaleGeneration(string $nodeId, string $documentId, string $id, int $generation, int $expectedVersion): TextSuggestion
    {
        $this->assertUuid($id);
        $subject = $this->subject();

        return $this->database->transaction(function () use ($nodeId, $documentId, $id, $generation, $expectedVersion, $subject): TextSuggestion {
            $drive = $this->driveWithoutIdempotency();
            $nodeSnap = $drive->nodes()->find($nodeId);
            $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
            if (($abilities[Ability::Edit->value] ?? false) !== true) {
                throw PermissionDeniedException::forAbility(Ability::Edit->value);
            }

            $conn = $this->lookup->connection();
            $query = $this->suggestions->query($conn)
                ->where('id', $id)
                ->where('document_id', $documentId)
                ->where('generation', $generation);
            $rows = $this->suggestions->read((clone $query)->lockForUpdate());
            $existing = $rows[0] ?? null;

            if ($existing === null || $existing->state !== 'open' || $existing->version !== $expectedVersion) {
                throw SuggestionConflictException::because('The stale-generation suggestion could not be rejected.', 'generation_changed');
            }

            $this->suggestions->update($query, $existing, [
                'state' => 'rejected',
                'decided_by_subject' => $subject,
                'decided_at' => now()->utc()->toDateTimeString(),
                'receipt_id' => null,
                'target_selector' => SuggestionRepository::selector(
                    $existing->from,
                    $existing->to,
                    $existing->parentId,
                    true,
                    $existing->isStructural() ? $existing->targetSelector : [],
                    'generation_changed',
                    $existing->conflictWithId,
                ),
            ]);

            $fresh = $this->suggestions->read($query);

            return new TextSuggestion(
                id: $fresh[0]->id,
                documentId: $fresh[0]->documentId,
                proposalId: $fresh[0]->proposalId,
                groupId: $fresh[0]->groupId,
                parentId: $fresh[0]->parentId,
                generation: $fresh[0]->generation,
                basePosition: $fresh[0]->basePosition,
                schemaVersion: $fresh[0]->schemaVersion,
                authorSubject: $fresh[0]->authorSubject,
                kind: $fresh[0]->kind,
                targetBlockId: $fresh[0]->targetBlockId,
                from: $fresh[0]->from,
                to: $fresh[0]->to,
                before: $fresh[0]->before,
                after: $fresh[0]->after,
                state: $fresh[0]->state,
                conflicted: $fresh[0]->conflicted,
                version: $fresh[0]->version,
                receiptId: $fresh[0]->receiptId,
                decidedBySubject: $fresh[0]->decidedBySubject,
                decidedAt: $fresh[0]->decidedAt,
                targetSelector: $fresh[0]->targetSelector,
                payload: $fresh[0]->payload,
                conflictReason: 'generation_changed',
                conflictWithId: $fresh[0]->conflictWithId,
                journalCommandId: $fresh[0]->journalCommandId,
                journalState: $fresh[0]->journalState,
                committedPosition: $fresh[0]->committedPosition,
                generationChanged: true,
            );
        });
    }

    /**
     * @param  array<string, int>  $expectedVersions
     * @param  list<string>|null  $ifMatch
     * @return list<TextSuggestion>
     */
    public function acceptGroup(int|string $nodeId, string $groupId, int $generation, array $expectedVersions, ?string $commandId = null, ?array $ifMatch = null): array
    {
        return $this->decide($nodeId, $generation, $expectedVersions, 'accepted', $groupId, $commandId, $ifMatch);
    }

    /**
     * @param  array<string, int>  $expectedVersions
     * @param  list<string>|null  $ifMatch
     * @return list<TextSuggestion>
     */
    public function rejectGroup(int|string $nodeId, string $groupId, int $generation, array $expectedVersions, ?string $commandId = null, ?array $ifMatch = null): array
    {
        return $this->decide($nodeId, $generation, $expectedVersions, 'rejected', $groupId, $commandId, $ifMatch);
    }

    /** @param list<string>|null $ifMatch */
    private function saveViaJournal(
        string $nodeId,
        TextSuggestionInput|StructuralSuggestionInput $input,
        ?int $expectedVersion,
        ?array $ifMatch,
    ): TextSuggestion {
        $author = $this->subject();

        /** @var TextSuggestion $saved */
        $saved = $this->journalWrite(
            $this->saveSpec(),
            function () use ($nodeId, $input, $expectedVersion, $author, $ifMatch): TextSuggestion {
                $drive = $this->driveWithoutIdempotency();
                $nodeSnap = $drive->nodes()->find($nodeId);
                $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
                if (($abilities[Ability::Edit->value] ?? false) !== true) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $this->documents->in($drive)->lockForRelatedWrite($nodeId);
                $docMeta = $this->collaboration->lock($nodeId);
                if ($docMeta->writeMode !== 'journal') {
                    throw SuggestionConflictException::because('Document write mode changed from journal.', 'write_mode_changed');
                }
                $this->capabilitiesResolver->assertDocumentSupported($docMeta->type, $docMeta->schemaVersion);
                $this->capabilitiesResolver->assertInputSupported($input);

                if ($input->generation !== $docMeta->generation || $input->schemaVersion !== $docMeta->schemaVersion) {
                    throw new SuggestionConflictException('The suggestion generation or schema version is stale.');
                }

                $documentId = $docMeta->documentId;
                $logSnapshot = $this->journalBridge->snapshot($nodeId, $input->generation);
                $headSeq = $logSnapshot !== null ? $logSnapshot->headSeq : 0;

                // Validate basePosition against journal head (or unseeded initial version when headSeq === 0).
                if ($headSeq === 0) {
                    if ($input->basePosition !== 0 && $input->basePosition !== $docMeta->stateVersion) {
                        throw new SuggestionConflictException('The journal suggestion base position is stale.');
                    }
                } elseif ($input->basePosition !== $headSeq) {
                    throw new SuggestionConflictException('The journal suggestion base position is stale.');
                }

                $conn = $this->lookup->connection();
                $this->syncPendingJournalLocked($conn, $nodeId, $documentId, $input->generation);

                $query = $this->suggestions->forDocument($conn, $documentId, $input->generation)->lockForUpdate();
                $rows = array_column($this->suggestions->read($query), null, 'id');
                $current = $rows[$input->id] ?? null;
                $this->assertSaveAmendable($current, $input, $this->saveVersion($current, $expectedVersion, $ifMatch), $author);

                $settledDoc = $logSnapshot?->tail === [] ? $logSnapshot->content : null;
                if ($input instanceof StructuralSuggestionInput && $settledDoc === null) {
                    throw new JournalProjectionPending($docMeta->pending);
                }
                $this->validateDraft($input, $settledDoc, $rows);

                return $this->persistDraft($conn, $query, $documentId, $input->generation, $input->basePosition, $docMeta->schemaVersion, $author, $input, $current, $rows, $settledDoc);
            },
            $this->receipt($nodeId, $this->saveFingerprint($input, $expectedVersion, $ifMatch)),
        );

        return $saved;
    }

    /**
     * @param  array<string, int>  $versions
     * @param  list<string>|null  $ifMatch
     * @return list<TextSuggestion>
     */
    private function decide(
        int|string $nodeId,
        int $generation,
        array $versions,
        string $decision,
        ?string $groupId = null,
        ?string $commandId = null,
        ?array $ifMatch = null,
    ): array {
        if ($versions === [] && $ifMatch === null) {
            throw PreconditionRequiredException::missing('If-Match');
        }
        foreach ($versions as $id => $version) {
            $this->assertUuid($id);
            if ($version < 1 && $ifMatch === null) {
                throw PreconditionRequiredException::missing('If-Match');
            }
        }
        if ($groupId !== null) {
            $this->assertUuid($groupId);
        }

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $nodeSnap = $drive->nodes()->find($nodeId);
        $docMeta = $this->documents->in($drive)->metadata($nodeSnap);

        if ($docMeta->writeMode === 'journal') {
            return $this->decideViaJournal($nodeSnap->id, $generation, $versions, $decision, $groupId, $commandId, $ifMatch);
        }

        $verb = $this->decisionVerb($decision, $groupId);
        $fingerprint = $this->decisionFingerprint($generation, $versions, $groupId, $commandId, $ifMatch);

        return $this->writeSnapshot($this->decisionSpec($verb), $nodeSnap->id, $fingerprint, function (LockedDocumentMutation $locked) use ($generation, $versions, $groupId, $decision, $ifMatch): array {
            $state = $locked->snapshot();
            if ($state->generation !== $generation) {
                throw SuggestionConflictException::because('The suggestion generation is stale.', 'generation_changed');
            }
            $subject = $this->subject();
            $query = $this->suggestions->forDocument($locked->connection(), $state->documentId, $generation)->lockForUpdate();
            $all = array_column($this->suggestions->read($query), null, 'id');
            [$selected, $versions] = $this->decisionSelection($all, $versions, $groupId, $ifMatch);
            if ($this->isReplay($selected, $versions, $decision, $subject)) {
                return array_values($selected);
            }

            $this->assertSnapshot($state);
            $this->capabilitiesResolver->assertDocumentSupported($state->type, $state->schemaVersion);
            foreach ($selected as $id => $row) {
                if ($row->state !== 'open' || $row->isPendingJournal() || $row->version !== $versions[$id] || $row->proposalId !== null) {
                    throw new SuggestionConflictException('The selection is stale or requires a proposal decision.');
                }
            }
            $others = array_diff_key($all, $selected);
            $receipt = $decision === 'accepted' ? (string) Str::uuid7() : null;
            if ($decision === 'accepted') {
                $this->acceptSnapshot($locked, $state, $query, $selected, $others);
            } else {
                $supersededIds = $this->rejectDescendants($query, $selected, $others, $subject);
                $this->clearResolvedConflicts($query, $all, array_values(array_unique([...array_keys($selected), ...$supersededIds])), doc: $state->content->toArray());
            }
            foreach ($selected as $row) {
                $this->recordDecision($query, $row, $decision, $subject, $receipt);
            }

            return $this->suggestions->read($query->whereIn('id', array_keys($selected)));
        });
    }

    /**
     * @param  array<string, int>  $versions
     * @param  list<string>|null  $ifMatch
     * @return list<TextSuggestion>
     */
    private function decideViaJournal(
        string $nodeId,
        int $generation,
        array $versions,
        string $decision,
        ?string $groupId,
        ?string $commandId,
        ?array $ifMatch,
    ): array {
        $verb = $this->decisionVerb($decision, $groupId);
        $subject = $this->subject();
        $fingerprint = $this->decisionFingerprint($generation, $versions, $groupId, $commandId, $ifMatch);

        /** @var list<TextSuggestion> $outcome */
        $outcome = $this->journalWrite(
            $this->decisionSpec($verb),
            function () use ($nodeId, $generation, $versions, $decision, $groupId, $subject, $commandId, $ifMatch, $verb): array {
                $drive = $this->driveWithoutIdempotency();
                $nodeSnap = $drive->nodes()->find($nodeId);
                $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
                if (($abilities[Ability::Edit->value] ?? false) !== true) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $this->documents->in($drive)->lockForRelatedWrite($nodeId);
                $meta = $this->collaboration->lock($nodeId);
                if ($meta->writeMode !== 'journal') {
                    throw SuggestionConflictException::because('Document write mode changed from journal.', 'write_mode_changed');
                }
                if ($meta->generation !== $generation) {
                    throw SuggestionConflictException::because('The suggestion generation is stale.', 'generation_changed');
                }
                $this->capabilitiesResolver->assertDocumentSupported($meta->type, $meta->schemaVersion);

                $conn = $this->lookup->connection();
                $documentId = $meta->documentId;
                $this->syncPendingJournalLocked($conn, $nodeId, $documentId, $generation);

                $query = $this->suggestions->forDocument($conn, $documentId, $generation)->lockForUpdate();
                $all = array_column($this->suggestions->read($query), null, 'id');
                [$selected, $versions] = $this->decisionSelection($all, $versions, $groupId, $ifMatch);

                $resolvedCommandId = $this->resolveCommandId($commandId, $decision, $generation, $versions, $groupId);
                $requestDigest = SuggestionJournalBridge::canonicalDigest([$verb, $this->decisionFingerprint($generation, $versions, $groupId, $resolvedCommandId)]);

                if ($this->isJournalReplay($conn, $selected, $versions, $decision, $subject, $resolvedCommandId, $requestDigest)) {
                    return array_values($selected);
                }

                $actor = $this->journalBridge->actor($this->context, $nodeId, $subject);
                foreach ($selected as $id => $row) {
                    if ($row->state !== 'open' || $row->isPendingJournal() || $row->version !== $versions[$id] || $row->proposalId !== null) {
                        throw new SuggestionConflictException('The selection is stale or requires a proposal decision.');
                    }
                }

                $logSnapshot = $this->journalBridge->snapshot($nodeId, $generation);
                $headSeq = $logSnapshot !== null ? $logSnapshot->headSeq : 0;

                $settledDoc = $logSnapshot?->tail === [] ? $logSnapshot->content : null;
                $effects = [];
                $destroyed = [];
                $readPosition = $headSeq;
                if ($decision === 'accepted') {
                    $structural = array_any($selected, static fn (TextSuggestion $row): bool => $row->isStructural());
                    if ($structural && $settledDoc === null) {
                        throw new JournalProjectionPending($meta->pending);
                    }
                    foreach ($selected as $row) {
                        if ($row->basePosition !== $headSeq && ! ($headSeq === 0 && $row->basePosition === $meta->stateVersion)) {
                            throw new SuggestionConflictException('The journal suggestion base position is stale.');
                        }
                    }
                    $applied = $this->prepareSelection($selected, $meta->schemaVersion, $settledDoc);
                    if ($structural && $applied !== null) {
                        $destroyed = $applied['destroyedBlockIds'];
                        $effects[] = ['effect' => 'replace_review_document', 'expected' => $settledDoc, 'replacement' => $applied['doc']];
                    } else {
                        $effects = $this->buildAtomicEffects($selected);
                    }
                }

                $proposalUuid = $groupId ?? SuggestionJournalBridge::deterministicUuid('suggestion-proposal:'.$resolvedCommandId);
                $suggestionSnapshots = array_values(array_map(static fn (TextSuggestion $row): array => [
                    'id' => $row->id,
                    'group_id' => $row->groupId,
                    'kind' => $row->kind,
                    'target_block_id' => $row->targetBlockId,
                    'selector' => $row->targetSelector,
                    'payload' => $row->payload,
                    'version' => $row->version,
                    'author_subject' => $row->authorSubject,
                ], $selected));

                $intent = [
                    'operation' => $decision === 'accepted' ? 'review.suggestion.accept' : 'review.suggestion.reject',
                    'decision' => $decision,
                    'document_id' => $documentId,
                    'generation' => $generation,
                    'group_id' => $groupId,
                    'suggestions' => $suggestionSnapshots,
                    'atomic_effects' => $effects,
                ];
                $digestHex = SuggestionJournalBridge::canonicalDigest($intent);

                $record = $this->journalBridge->register(
                    actor: $actor,
                    commandId: $resolvedCommandId,
                    proposalUuid: $proposalUuid,
                    digestHex: $digestHex,
                    generation: $generation,
                    schemaVersion: $meta->schemaVersion,
                    readPosition: $readPosition,
                    intent: $intent,
                );

                $journalState = $record['state'] ?? 'running';
                if ($journalState === 'rejected') {
                    throw SuggestionConflictException::because(
                        sprintf('Journal command rejected (%s).', is_string($record['rejection_code'] ?? null) ? $record['rejection_code'] : 'journal_rejected'),
                        is_string($record['rejection_code'] ?? null) ? $record['rejection_code'] : 'journal_rejected',
                    );
                }

                if ($journalState === 'committed' && is_string($record['receipt_id'] ?? null)) {
                    $toSeq = is_int($record['to_seq'] ?? null) ? $record['to_seq'] : null;
                    $this->finalizeJournalOutcome($conn, $query, $all, array_keys($selected), $decision, $subject, $resolvedCommandId, $record['receipt_id'], $toSeq, $readPosition, $requestDigest, $versions, $destroyed);

                    return $this->suggestions->read((clone $query)->whereIn('id', array_keys($selected)));
                }

                // Store pending_journal metadata while keeping state = 'open' per SQL receipt_id invariant.
                $pendingMeta = [
                    'command_id' => $resolvedCommandId,
                    'command_row_id' => $record['id'] ?? null,
                    'fingerprint' => $record['fingerprint'] ?? null,
                    'state' => 'running',
                    'decision' => $decision,
                    'decided_by_subject' => $subject,
                    'read_position' => $readPosition,
                    'request_digest' => $requestDigest,
                    'versions' => $versions,
                    'destroyed_block_ids' => $destroyed,
                ];
                foreach ($selected as $row) {
                    $prov = $this->suggestions->readProvenance($conn, $row->id) ?? [];
                    $prov['schema'] = 1;
                    $prov['pending_journal'] = $pendingMeta;
                    unset($prov['last_journal_rejection']);
                    $this->suggestions->update($query, $row, [
                        'provenance_json' => SuggestionRepository::encodeProvenance($prov),
                    ]);
                }

                return $this->suggestions->read((clone $query)->whereIn('id', array_keys($selected)));
            },
            $this->receipt($nodeId, $fingerprint),
        );

        return $outcome;
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, mixed>|null  $settledDoc
     * @return ($settledDoc is null ? null : array{doc: array<string, mixed>, destroyedBlockIds: list<string>})
     */
    private function prepareSelection(array $selected, int $schemaVersion, ?array $settledDoc): ?array
    {
        $sorted = array_values($selected);
        foreach ($sorted as $row) {
            if ($row->conflicted || $row->parentId !== null || $row->schemaVersion !== $schemaVersion) {
                throw new SuggestionConflictException('Refresh the target or resolve its conflict or parent before accepting.');
            }
        }

        $count = count($sorted);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->overlapDetector->overlaps($sorted[$i], $sorted[$j], doc: $settledDoc)) {
                    throw new SuggestionConflictException('A decision cannot accept overlapping edits together.');
                }
            }
        }

        if ($settledDoc === null) {
            return null;
        }

        $doc = $settledDoc;
        $destroyed = [];
        foreach ($this->orderedSelection($selected) as $row) {
            $applied = $this->structuralEditor->apply($doc, $row);
            $doc = $applied['doc'];
            $destroyed = [...$destroyed, ...$applied['destroyedBlockIds']];
        }

        return ['doc' => $doc, 'destroyedBlockIds' => array_values(array_unique($destroyed))];
    }

    private function syncPendingJournalState(string $nodeId, string $documentId, int $generation): void
    {
        // Only a pending journal command needs the locked sync: an open suggestion keeps its provenance after a
        // journal rejection, so a non-null provenance alone would lock the document on every list.
        $open = $this->suggestions->forDocument($this->lookup->connection(), $documentId, $generation)->where('state', 'open');
        $openWithPending = array_filter(
            $this->suggestions->readProvenances($open),
            static fn (array $provenance): bool => is_array($provenance['pending_journal'] ?? null),
        );
        if ($openWithPending === []) {
            return;
        }

        $this->database->transaction(function () use ($nodeId, $documentId, $generation): void {
            $meta = $this->collaboration->lock($nodeId);
            if ($meta->generation !== $generation || $meta->documentId !== $documentId || $meta->trashed
                || $meta->driveId !== (string) $this->context->driveId()) {
                return;
            }
            $this->syncPendingJournalLocked($this->lookup->connection(), $nodeId, $documentId, $generation);
        });
    }

    private function syncPendingJournalLocked(Connection $conn, string $nodeId, string $documentId, int $generation): void
    {
        $query = $this->suggestions->forDocument($conn, $documentId, $generation)->lockForUpdate();
        $all = array_column($this->suggestions->read($query), null, 'id');

        /** @var array<string, list<string>> $groupsByCommand */
        $groupsByCommand = [];
        /** @var array<string, array<string, mixed>> $metaByCommand */
        $metaByCommand = [];

        // The rows are locked by the read above; their provenance comes in one more read, not one per row.
        $provenances = $this->suggestions->readProvenances((clone $query)->where('state', 'open'));
        foreach ($all as $row) {
            if ($row->state !== 'open') {
                continue;
            }
            $prov = $provenances[$row->id] ?? null;
            $pending = is_array($prov['pending_journal'] ?? null) ? $prov['pending_journal'] : null;
            $cmdId = is_array($pending) && is_string($pending['command_id'] ?? null) ? $pending['command_id'] : null;
            if ($cmdId === null) {
                continue;
            }
            $subject = $pending['decided_by_subject'] ?? null;
            if (! is_string($subject)) {
                continue;
            }
            $groupKey = json_encode([$subject, $cmdId], JSON_THROW_ON_ERROR);
            $groupsByCommand[$groupKey][] = $row->id;
            $metaByCommand[$groupKey] ??= $pending;
        }

        $settled = [];
        foreach ($groupsByCommand as $groupKey => $rowIds) {
            $pendingMeta = $metaByCommand[$groupKey];
            $cmdId = $pendingMeta['command_id'] ?? null;
            $decidedBy = $pendingMeta['decided_by_subject'] ?? null;
            if (! is_string($cmdId) || ! is_string($decidedBy)) {
                continue;
            }
            $decision = ($pendingMeta['decision'] ?? null) === 'rejected' ? 'rejected' : 'accepted';

            $actor = $this->journalBridge->actor($this->context, $nodeId, $decidedBy);
            $record = $this->journalBridge->status($actor, $cmdId);
            if ($record === null || ($record['id'] ?? null) !== ($pendingMeta['command_row_id'] ?? null)
                || ($record['fingerprint'] ?? null) !== ($pendingMeta['fingerprint'] ?? null)) {
                continue;
            }
            if (($record['state'] ?? null) !== 'running') {
                $settled[] = [$record, $rowIds, $pendingMeta, $decision, $decidedBy, $cmdId];
            }
        }
        usort($settled, static fn (array $a, array $b): int => [
            $a[0]['base_seq'] ?? 0, $a[0]['id'],
        ] <=> [$b[0]['base_seq'] ?? 0, $b[0]['id']]);

        foreach ($settled as [$record, $rowIds, $pendingMeta, $decision, $decidedBy, $cmdId]) {
            $state = $record['state'] ?? null;
            if ($state === 'committed' && is_string($record['receipt_id'] ?? null)) {
                $toSeq = is_int($record['to_seq'] ?? null) ? $record['to_seq'] : null;
                $freshAll = array_column($this->suggestions->read(clone $query), null, 'id');
                $this->finalizeJournalOutcome(
                    $conn,
                    $query,
                    $freshAll,
                    $rowIds,
                    $decision,
                    $decidedBy,
                    $cmdId,
                    $record['receipt_id'],
                    $toSeq,
                    is_int($record['base_seq'] ?? null) ? $record['base_seq'] : null,
                    is_string($pendingMeta['request_digest'] ?? null) ? $pendingMeta['request_digest'] : null,
                    $this->submittedVersions($pendingMeta['versions'] ?? null),
                    $this->stringList($pendingMeta['destroyed_block_ids'] ?? null),
                );
            } elseif ($state === 'rejected') {
                $rejCode = is_string($record['rejection_code'] ?? null) ? $record['rejection_code'] : 'journal_rejected';
                $fresh = array_column($this->suggestions->read((clone $query)->whereIn('id', $rowIds)), null, 'id');
                foreach ($rowIds as $rid) {
                    $row = $fresh[$rid] ?? null;
                    if ($row === null || $row->state !== 'open') {
                        continue;
                    }
                    $prov = $this->suggestions->readProvenance($conn, $rid) ?? [];
                    unset($prov['pending_journal']);
                    $prov['last_journal_rejection'] = $rejCode;
                    $this->suggestions->update($query, $row, [
                        'provenance_json' => SuggestionRepository::encodeProvenance($prov),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, TextSuggestion>  $all
     * @param  list<string>  $selectedIds
     * @param  array<string, int>  $versions
     * @param  list<string>  $destroyed
     */
    private function finalizeJournalOutcome(
        Connection $conn,
        Builder $query,
        array $all,
        array $selectedIds,
        string $decision,
        string $subject,
        string $commandId,
        string $receiptId,
        ?int $toSeq,
        ?int $baseSeq,
        ?string $requestDigest,
        array $versions,
        array $destroyed,
    ): void {
        $selected = [];
        foreach ($selectedIds as $sid) {
            if (isset($all[$sid]) && $all[$sid]->state === 'open') {
                $selected[$sid] = $all[$sid];
            }
        }
        if ($selected === []) {
            return;
        }
        $others = array_diff_key($all, $selected);

        if ($decision === 'accepted') {
            foreach ($selected as $row) {
                $prov = $this->suggestions->readProvenance($conn, $row->id) ?? [];
                unset($prov['pending_journal']);
                $prov['schema'] = 1;
                $prov['journal_command_id'] = $commandId;
                $prov['journal_state'] = 'committed';
                $prov['journal_request_digest'] = $requestDigest;
                $prov['journal_expected_version'] = $versions[$row->id] ?? $row->version - 1;
                if ($toSeq !== null) {
                    $prov['committed_position'] = $toSeq;
                }
                $this->suggestions->update($query, $row, [
                    'state' => 'accepted',
                    'decided_by_subject' => $subject,
                    'decided_at' => now()->utc()->toDateTimeString(),
                    'receipt_id' => $receiptId,
                    'provenance_json' => SuggestionRepository::encodeProvenance($prov),
                ]);
            }
            $newPosition = $toSeq ?? max(array_map(static fn (TextSuggestion $r): int => $r->basePosition, $selected));
            $submittedBase = reset($selected)->basePosition;
            $ordered = $this->orderedSelection($selected);
            foreach ($others as $other) {
                if ($other->state === 'open' && ($other->basePosition === $baseSeq
                    || ($baseSeq === 0 && $other->basePosition === $submittedBase))) {
                    $this->rebaseAfterAccept($query, $other, $ordered, $newPosition, $destroyed);
                }
            }

            return;
        }

        $supersededIds = $this->rejectDescendants($query, $selected, $others, $subject);
        $this->clearResolvedConflicts($query, $all, array_values(array_unique([...array_keys($selected), ...$supersededIds])));

        foreach ($selected as $row) {
            $prov = $this->suggestions->readProvenance($conn, $row->id) ?? [];
            unset($prov['pending_journal']);
            $prov['schema'] = 1;
            $prov['journal_command_id'] = $commandId;
            $prov['journal_state'] = 'committed';
            $prov['journal_receipt_id'] = $receiptId;
            $prov['journal_request_digest'] = $requestDigest;
            $prov['journal_expected_version'] = $versions[$row->id] ?? $row->version - 1;
            if ($toSeq !== null) {
                $prov['committed_position'] = $toSeq;
            }
            $this->suggestions->update($query, $row, [
                'state' => 'rejected',
                'decided_by_subject' => $subject,
                'decided_at' => now()->utc()->toDateTimeString(),
                'receipt_id' => null,
                'provenance_json' => SuggestionRepository::encodeProvenance($prov),
            ]);
        }
    }

    /**
     * @param  array<string, TextSuggestion>  $all
     * @param  array<string, int>  $versions
     * @param  list<string>|null  $ifMatch
     * @return array{array<string, TextSuggestion>, array<string, int>}
     */
    private function decisionSelection(array $all, array $versions, ?string $groupId, ?array $ifMatch): array
    {
        $group = $groupId === null ? [] : array_filter($all, static fn (TextSuggestion $row): bool => $row->groupId === $groupId);
        $selected = $versions === [] ? $group : array_intersect_key($all, $versions);
        if ($selected === [] || ($versions !== [] && count($selected) !== count($versions))
            || ($groupId !== null && array_keys($group) !== array_keys($selected))) {
            throw new SuggestionConflictException('The selected suggestions or group membership have changed.');
        }

        if ($ifMatch !== null) {
            foreach ($selected as $id => $row) {
                $provided = $versions[$id] ?? 0;
                if (! in_array($row->etag(), $ifMatch, true) || ($provided !== 0 && $provided !== $row->version)) {
                    throw StaleWriteException::of('review.suggestion', $id);
                }
                $versions[$id] = $row->version;
            }
        }

        return [$selected, $versions];
    }

    /** @param list<string>|null $ifMatch */
    private function saveVersion(?TextSuggestion $current, ?int $version, ?array $ifMatch): int
    {
        if ($ifMatch === null) {
            if ($version === null && $current !== null) {
                throw PreconditionRequiredException::missing('If-Match');
            }

            return $version ?? 0;
        }
        if ($current === null || ! in_array($current->etag(), $ifMatch, true)
            || ($version !== null && $version !== $current->version)) {
            throw StaleWriteException::of('review.suggestion', $current === null ? 'missing' : $current->id);
        }

        return $current->version;
    }

    private function assertSaveAmendable(
        ?TextSuggestion $current,
        TextSuggestionInput|StructuralSuggestionInput $input,
        int $expectedVersion,
        string $author,
    ): void {
        $isStructuralInput = $input instanceof StructuralSuggestionInput;
        if (($current->version ?? 0) !== $expectedVersion || ($current !== null && (
            $current->state !== 'open'
            || $current->isPendingJournal()
            || $current->authorSubject !== $author
            || $current->groupId !== $input->groupId
            || $current->proposalId !== $input->proposalId
            || $current->parentId !== $input->parentId
            || ($isStructuralInput && $current->kind !== $input->kind)
            || (! $isStructuralInput && $current->isStructural())
        ))) {
            throw new SuggestionConflictException('The suggestion is unavailable at the supplied version.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $doc
     * @param  array<string, TextSuggestion>  $rows
     */
    private function validateDraft(
        TextSuggestionInput|StructuralSuggestionInput $input,
        ?array $doc,
        array $rows,
    ): void {
        $author = $this->subject();
        foreach ($rows as $row) {
            if ($row->id !== $input->id && $row->groupId === $input->groupId && (
                $row->state !== 'open' || $row->isPendingJournal() || $row->authorSubject !== $author || $row->proposalId !== $input->proposalId
            )) {
                throw new SuggestionConflictException('This group cannot be extended.');
            }
            if ($row->state === 'open' && $row->parentId === $input->id
                && ($row->targetBlockId !== $input->targetBlockId || ($rows[$input->id]->after ?? null) !== $input->after)) {
                throw new SuggestionConflictException('Resolve the nested edit before changing its parent text.');
            }
        }

        if ($input->parentId !== null) {
            if ($input instanceof StructuralSuggestionInput) {
                throw new SuggestionConflictException('Structural suggestions cannot nest inside another draft insertion.');
            }
            $parent = $rows[$input->parentId] ?? null;
            if ($parent === null || $parent->state !== 'open' || $parent->isPendingJournal() || $parent->proposalId !== null || $parent->targetBlockId !== $input->targetBlockId
                || $parent->conflicted || $parent->basePosition !== $input->basePosition || $parent->schemaVersion !== $input->schemaVersion
                || $parent->after === '' || SnapshotTextEdit::slice($parent->after, $input->from, $input->to) !== $input->before) {
                throw new SuggestionConflictException('The parent insertion is unavailable at the supplied text range.');
            }

            return;
        }

        if ($doc !== null) {
            $this->structuralEditor->apply($doc, $input);
        }
    }

    /**
     * @param  array<string, TextSuggestion>  $rows
     * @param  array<string, mixed>|null  $doc
     */
    private function persistDraft(
        Connection $conn,
        Builder $query,
        string $documentId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $author,
        TextSuggestionInput|StructuralSuggestionInput $input,
        ?TextSuggestion $current,
        array $rows,
        ?array $doc,
    ): TextSuggestion {
        if ($current === null) {
            $open = count(array_filter($rows, static fn (TextSuggestion $row): bool => $row->state === 'open'));
            if ($open >= self::MAX_OPEN_PER_DOCUMENT) {
                throw UnsupportedSuggestionException::because(
                    'the document already has '.self::MAX_OPEN_PER_DOCUMENT.' open suggestions; accept or reject some first.',
                    'open_suggestion_limit',
                );
            }
        }

        $conflicted = false;
        $conflictReason = null;
        $conflictWithId = null;

        // Overlap conflict policy v1: an open overlapping suggestion where either side is structural
        // keeps the new suggestion in `open` with an explicit conflict while preserving original author.
        foreach ($rows as $existing) {
            if ($existing->id === $input->id || $existing->state !== 'open' || $existing->conflictWithId === $input->id) {
                continue;
            }
            $eitherStructural = $input instanceof StructuralSuggestionInput || $existing->isStructural();
            if (! $eitherStructural) {
                continue;
            }
            if ($this->overlapDetector->overlaps($input, $existing, doc: $doc)) {
                $conflicted = true;
                $conflictReason = 'overlap_conflict';
                $conflictWithId = $existing->id;
                break;
            }
        }

        $extraSelector = $input instanceof StructuralSuggestionInput ? $input->selector : [];
        unset($extraSelector['container_anchor']);
        $container = $this->overlapDetector->container($input, $doc);
        if ($container !== null) {
            $extraSelector['container_anchor'] = $container;
        }
        $payloadArray = $input instanceof StructuralSuggestionInput
            ? $input->payload
            : ['before' => $input->before, 'after' => $input->after];

        $values = [
            'base_position' => $basePosition,
            'kind' => $input->kind,
            'target_block_id' => $input->targetBlockId,
            'target_selector' => SuggestionRepository::selector(
                $input->from,
                $input->to,
                $input->parentId,
                $conflicted,
                $extraSelector,
                $conflictReason,
                $conflictWithId,
            ),
            'payload' => json_encode($payloadArray, JSON_THROW_ON_ERROR),
        ];

        if ($current === null) {
            $this->suggestions->query($conn)->insert($values + [
                'id' => $input->id,
                'tenant_id' => $this->lookup->normalizeTenantId($this->context->operation->tenant->id),
                'document_id' => $documentId,
                'group_id' => $input->groupId,
                'proposal_id' => $input->proposalId,
                'generation' => $generation,
                'schema_version' => $schemaVersion,
                'author_subject' => $author,
            ]);
        } else {
            $this->suggestions->update($query, $current, $values + ['schema_version' => $schemaVersion]);
            $fresh = array_column($this->suggestions->read(clone $query), null, 'id');
            $this->clearResolvedConflicts($query, $fresh, [$current->id], $fresh[$current->id], $doc);
        }

        return $this->suggestions->read((clone $query)->where('id', $input->id))[0];
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, int>  $versions
     */
    private function isReplay(array $selected, array $versions, string $decision, string $subject): bool
    {
        $receipt = reset($selected)->receiptId ?? null;
        foreach ($selected as $id => $row) {
            if ($row->state !== $decision || $row->decidedBySubject !== $subject
                || $row->version - 1 !== $versions[$id] || ($decision === 'accepted' ? $row->receiptId !== $receipt : $row->receiptId !== null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, TextSuggestion>  $others
     */
    private function acceptSnapshot(LockedDocumentMutation $locked, DocumentMutationState $state, Builder $query, array $selected, array $others): void
    {
        foreach ($selected as $row) {
            if ($row->basePosition !== $state->position) {
                throw new SuggestionConflictException('The suggestion base position is stale.');
            }
        }

        $baseDoc = $state->content->toArray();
        $applied = $this->prepareSelection($selected, $state->schemaVersion, $baseDoc);
        $codec = $this->types->get($state->type)->codec();
        $result = $locked->replace($codec->decode($applied['doc'], $state->schemaVersion), $state->position);
        $ordered = $this->orderedSelection($selected);
        $destroyedBlockIds = $applied['destroyedBlockIds'];

        foreach ($others as $row) {
            if ($row->state === 'open' && $row->basePosition === $state->position && $row->schemaVersion === $state->schemaVersion) {
                $this->rebaseAfterAccept($query, $row, $ordered, $result->position, $destroyedBlockIds, $baseDoc);
            }
        }
    }

    /**
     * @param  list<TextSuggestion>  $selected
     * @param  list<string>  $destroyedBlockIds
     * @param  array<string, mixed>|null  $doc
     */
    private function rebaseAfterAccept(Builder $query, TextSuggestion $row, array $selected, int $position, array $destroyedBlockIds, ?array $doc = null): void
    {
        $from = $row->from;
        $to = $row->to;
        $parent = $row->parentId;
        $conflicted = $row->conflicted;
        $conflictReason = $row->conflictReason;
        $conflictWithId = $row->conflictWithId;

        foreach ($selected as $edit) {
            if ($parent === $edit->id && $edit->targetBlockId === $row->targetBlockId) {
                $parent = null;
                $from += $edit->from;
                $to += $edit->from;

                continue;
            }
            if ($parent === null && ! $conflicted) {
                if ($this->overlapDetector->overlaps($row, $edit, $destroyedBlockIds, $doc)) {
                    $conflicted = true;
                    $conflictReason = 'overlap_conflict';
                    $conflictWithId = $edit->id;
                } elseif ($this->hasTextRange($row) && ! $edit->isStructural()
                    && $edit->targetBlockId === $row->targetBlockId && $edit->to <= $row->from) {
                    $shift = SnapshotAnchorResolver::utf16Length($edit->after) - ($edit->to - $edit->from);
                    $from += $shift;
                    $to += $shift;
                }
            }
        }

        $extra = $row->isStructural() ? $row->targetSelector : [];
        $this->suggestions->update($query, $row, [
            'base_position' => $position,
            'target_selector' => SuggestionRepository::selector(
                $from,
                $to,
                $parent,
                $conflicted,
                $extra,
                $conflictReason,
                $conflictWithId,
            ),
        ]);
    }

    /**
     * @param  array<string, TextSuggestion>  $all
     * @param  list<string>  $rejectedIds
     * @param  array<string, mixed>|null  $doc
     */
    private function clearResolvedConflicts(Builder $query, array $all, array $rejectedIds, ?TextSuggestion $amended = null, ?array $doc = null): void
    {
        $rejectedLookup = array_flip($rejectedIds);
        $remainingOpen = array_filter(
            $all,
            static fn (TextSuggestion $s): bool => $s->state === 'open' && ! isset($rejectedLookup[$s->id]),
        );

        foreach ($remainingOpen as $open) {
            if (! $open->conflicted || $open->conflictWithId === null || ! isset($rejectedLookup[$open->conflictWithId])) {
                continue;
            }

            if ($amended !== null && $this->overlapDetector->overlaps($open, $amended, doc: $doc)) {
                continue;
            }
            $nextConflictWith = null;
            foreach ($remainingOpen as $other) {
                if ($other->id !== $open->id && $other->conflictWithId !== $open->id
                    && ($open->isStructural() || $other->isStructural())
                    && $this->overlapDetector->overlaps($open, $other, doc: $doc)) {
                    $nextConflictWith = $other->id;
                    break;
                }
            }

            $conflicted = $nextConflictWith !== null;
            $extra = $open->isStructural() ? $open->targetSelector : [];
            $this->suggestions->update($query, $open, [
                'target_selector' => SuggestionRepository::selector(
                    $open->from,
                    $open->to,
                    $open->parentId,
                    $conflicted,
                    $extra,
                    $conflicted ? 'overlap_conflict' : null,
                    $nextConflictWith,
                ),
            ]);
        }
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, TextSuggestion>  $others
     * @return list<string>
     */
    private function rejectDescendants(Builder $query, array $selected, array $others, string $subject): array
    {
        $children = [];
        foreach ($others as $row) {
            if ($row->state === 'open' && $row->parentId !== null) {
                $children[$row->parentId][] = $row;
            }
        }
        $superseded = [];
        $frontier = array_keys($selected);
        while (($parent = array_pop($frontier)) !== null) {
            foreach ($children[$parent] ?? [] as $child) {
                $this->recordDecision($query, $child, 'superseded', $subject, null);
                $superseded[] = $child->id;
                $frontier[] = $child->id;
            }
        }

        return $superseded;
    }

    private function recordDecision(Builder $query, TextSuggestion $row, string $decision, string $subject, ?string $receipt): void
    {
        $this->suggestions->update($query, $row, [
            'state' => $decision,
            'decided_by_subject' => $subject,
            'decided_at' => now()->utc()->toDateTimeString(),
            'receipt_id' => $receipt,
        ]);
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @return list<array<string, mixed>>
     */
    private function buildAtomicEffects(array $selected): array
    {
        $effects = [];
        foreach ($this->orderedSelection($selected) as $row) {
            $effects[] = [
                'effect' => 'replace_exact',
                'block_id' => $row->targetBlockId,
                'expected' => $row->before,
                'replacement' => $row->after,
                'from_utf16' => $row->from,
                'to_utf16' => $row->to,
            ];
        }

        return $effects;
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @return list<TextSuggestion>
     */
    private function orderedSelection(array $selected): array
    {
        $ordered = array_values($selected);
        usort($ordered, static function (TextSuggestion $a, TextSuggestion $b): int {
            $blockOrder = $a->targetBlockId <=> $b->targetBlockId;

            return $blockOrder !== 0 ? $blockOrder : ([$b->from, $b->to] <=> [$a->from, $a->to]);
        });

        return $ordered;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @return T
     */
    private function journalWrite(OperationSpec $spec, Closure $attempt, IdempotentWrite $receipt): mixed
    {
        if (! $this->journalBridge->isAvailable()) {
            throw SuggestionConflictException::because('Collaboration CommandJournal is unavailable.', 'journal_unavailable');
        }

        try {
            return $this->runner->write($spec, $this->context->operation, $attempt, $receipt);
        } catch (JournalProjectionPending $pending) {
            if ($pending->marker !== null) {
                $this->journalBridge->requestProjection($pending->marker);
            }

            throw SuggestionConflictException::because('Retry after the journal head has been projected.', 'projection_behind');
        }
    }

    private function hasTextRange(TextSuggestion $row): bool
    {
        return ! $row->isStructural()
            || $row->kind === SuggestionKind::MarkChange->value
            || ($row->kind === SuggestionKind::AttrChange->value && ($row->payload['node_type'] ?? null) === 'link');
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, int>  $versions
     */
    private function isJournalReplay(Connection $conn, array $selected, array $versions, string $decision, string $subject, string $commandId, string $digest): bool
    {
        foreach ($selected as $id => $row) {
            if ($row->journalCommandId !== $commandId) {
                return false;
            }
            $provenance = $this->suggestions->readProvenance($conn, $id) ?? [];
            $pending = $provenance['pending_journal'] ?? null;
            if ($row->isPendingJournal() && is_array($pending)) {
                if (($pending['decided_by_subject'] ?? null) !== $subject || ($pending['request_digest'] ?? null) !== $digest) {
                    return false;
                }
            } elseif ($row->state !== $decision || $row->decidedBySubject !== $subject
                || ($provenance['journal_request_digest'] ?? null) !== $digest
                || ($provenance['journal_expected_version'] ?? null) !== $versions[$id]) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> */
    private function submittedVersions(mixed $value): array
    {
        $versions = [];
        foreach (is_array($value) ? $value : [] as $id => $version) {
            if (is_string($id) && is_int($version)) {
                $versions[$id] = $version;
            }
        }

        return $versions;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private function saveSpec(): OperationSpec
    {
        return new OperationSpec('review.suggestions.save', new ModuleId('review'));
    }

    /** @param 'accept'|'reject'|'accept_group'|'reject_group' $verb */
    private function decisionSpec(string $verb): OperationSpec
    {
        $module = new ModuleId('review');

        return match ($verb) {
            'accept' => new OperationSpec('review.suggestions.accept', $module),
            'reject' => new OperationSpec('review.suggestions.reject', $module),
            'accept_group' => new OperationSpec('review.suggestions.accept_group', $module),
            'reject_group' => new OperationSpec('review.suggestions.reject_group', $module),
        };
    }

    /** @return 'accept'|'reject'|'accept_group'|'reject_group' */
    private function decisionVerb(string $decision, ?string $groupId): string
    {
        if ($groupId === null) {
            return $decision === 'accepted' ? 'accept' : 'reject';
        }

        return $decision === 'accepted' ? 'accept_group' : 'reject_group';
    }

    /**
     * @param  array<string, int>  $versions
     * @param  list<string>|null  $ifMatch
     * @return array<string, mixed>
     */
    private function decisionFingerprint(int $generation, array $versions, ?string $groupId, ?string $commandId, ?array $ifMatch = null): array
    {
        ksort($versions, SORT_STRING);

        return ['generation' => $generation, 'versions' => $versions, 'group' => $groupId, 'command' => $commandId, 'if_match' => $ifMatch];
    }

    /**
     * @param  list<string>|null  $ifMatch
     * @return array<string, mixed>
     */
    private function saveFingerprint(TextSuggestionInput|StructuralSuggestionInput $input, ?int $expectedVersion, ?array $ifMatch): array
    {
        return ['suggestion' => get_object_vars($input), 'expected_version' => $expectedVersion, 'if_match' => $ifMatch];
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $fingerprint
     * @param  Closure(LockedDocumentMutation): T  $attempt
     * @return T
     */
    private function writeSnapshot(OperationSpec $spec, string $nodeId, array $fingerprint, Closure $attempt): mixed
    {
        return $this->runner->write(
            $spec,
            $this->context->operation,
            fn (): mixed => $this->documents->in($this->driveWithoutIdempotency())->mutateForRelatedWrite($nodeId, $attempt),
            $this->receipt($nodeId, $fingerprint),
        );
    }

    /** @param array<string, mixed> $fingerprint */
    private function receipt(string $nodeId, array $fingerprint): IdempotentWrite
    {
        return new IdempotentWrite(
            new SuggestionReceiptCodec($nodeId),
            new ReviewNodeReplayAuthorizer($this->context, $this->vaulter, $nodeId, Ability::Edit),
            ['node' => $nodeId] + $fingerprint,
        );
    }

    /** @param array<string, int> $versions */
    private function resolveCommandId(?string $commandId, string $decision, int $generation, array $versions, ?string $groupId): string
    {
        if ($commandId !== null) {
            $trimmed = trim($commandId);
            if ($trimmed === '' || strlen($trimmed) > 255) {
                throw new SuggestionConflictException('Invalid journal command identifier.');
            }

            return $trimmed;
        }
        ksort($versions, SORT_STRING);

        return 'review:'.hash('sha256', json_encode([
            'decision' => $decision,
            'generation' => $generation,
            'group_id' => $groupId,
            'versions' => $versions,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertSnapshot(DocumentMutationState $state): void
    {
        if ($state->writeMode !== 'snapshot' || $state->pending || ! $state->content instanceof TipTapContent) {
            throw new SuggestionConflictException('Snapshot suggestion writes require a settled TipTap snapshot.');
        }
    }

    private function driveWithoutIdempotency(): DriveHandle
    {
        return ReviewDriveHandle::fromContext($this->vaulter, $this->context, withIdempotency: false);
    }

    private function subject(): string
    {
        $actor = $this->context->actor->ref();
        if ($actor->type === null || $actor->id === null) {
            throw new MissingActorException;
        }
        $subject = str_replace(['%', ':'], ['%25', '%3A'], $actor->type)
            .':'.str_replace(['%', ':'], ['%25', '%3A'], (string) $actor->id);
        if (strlen($subject) > 255 || str_contains($subject, "\0") || ! mb_check_encoding($subject, 'UTF-8')) {
            throw new SuggestionConflictException('The actor subject cannot be stored.');
        }

        return $subject;
    }

    private function assertUuid(string $id): void
    {
        if (! Str::isUuid($id) || $id !== strtolower($id)) {
            throw new SuggestionConflictException('Invalid suggestion identifier.');
        }
    }
}
