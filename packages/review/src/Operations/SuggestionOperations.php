<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Operations;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\Documents\DocumentsManager;
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
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Repositories\ReviewLookup;
use Vaulter\EditorPro\Review\Repositories\SuggestionRepository;
use Vaulter\EditorPro\Review\Suggestions\ReviewCapabilities;
use Vaulter\EditorPro\Review\Suggestions\SnapshotStructuralEdit;
use Vaulter\EditorPro\Review\Suggestions\SnapshotTextEdit;
use Vaulter\EditorPro\Review\Suggestions\SuggestionJournalBridge;
use Vaulter\EditorPro\Review\Suggestions\SuggestionOverlapDetector;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Exceptions\Access\MissingActorException;
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
    private ReviewCapabilities $capabilitiesResolver;

    private SnapshotStructuralEdit $structuralEditor;

    private SuggestionOverlapDetector $overlapDetector;

    private SuggestionJournalBridge $journalBridge;

    #[Internal]
    public function __construct(
        private DriveContext $context,
        private AtomicDocumentMutation $atomic,
        private SuggestionRepository $suggestions,
        private ReviewLookup $lookup,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
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
        $docMeta = $this->lookup->documentForNode($nodeSnap->id);

        return $this->capabilitiesResolver->build(
            documentType: $docMeta['type'],
            writeMode: $docMeta['write_mode'],
            journalSupported: $this->journalBridge->isAvailable(),
        );
    }

    /** @return list<TextSuggestion> */
    public function list(int|string $nodeId, ?string $proposalId = null): array
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $nodeSnap = $drive->nodes()->find($nodeId);
        $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
        if (($abilities[Ability::View->value] ?? false) !== true) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }
        $docMeta = $this->lookup->documentForNode($nodeSnap->id);
        $documentId = (string) $docMeta['id'];
        $generation = $docMeta['collab_epoch'];

        if ($docMeta['write_mode'] === 'journal' && $this->journalBridge->isAvailable()) {
            $this->syncPendingJournalState($nodeSnap->id, $documentId, $generation);
        }

        $query = $this->suggestions->forDocument($this->lookup->connection(), $documentId, $generation);
        if ($proposalId !== null) {
            $this->assertUuid($proposalId);
            $query->where('proposal_id', $proposalId);
        }

        return $this->suggestions->read($query);
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

    /** Create with version 0, or amend/retarget an open suggestion as its original author. */
    public function save(int|string $nodeId, TextSuggestionInput|StructuralSuggestionInput $input, int $expectedVersion = 0): TextSuggestion
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
        $docMeta = $this->lookup->documentForNode($nodeSnap->id);
        $this->capabilitiesResolver->assertDocumentSupported($docMeta['type'], $docMeta['schema_version']);
        $this->capabilitiesResolver->assertInputSupported($input);

        if ($docMeta['write_mode'] === 'journal') {
            return $this->saveViaJournal($nodeSnap->id, $input, $expectedVersion);
        }

        return $this->atomic->run($this->context, $nodeSnap->id, function (LockedDocumentMutation $locked) use ($input, $expectedVersion): TextSuggestion {
            $state = $locked->snapshot();
            $this->assertSnapshot($state);
            $this->capabilitiesResolver->assertDocumentSupported($state->type, $state->schemaVersion);
            $this->capabilitiesResolver->assertInputSupported($input);

            if ($input->generation !== $state->generation || $input->basePosition !== $state->position || $input->schemaVersion !== $state->schemaVersion) {
                throw new SuggestionConflictException('The suggestion base is stale.');
            }

            $author = $this->subject();
            $conn = $locked->connection();
            $query = $this->suggestions->forDocument($conn, $state->documentId, $state->generation);
            $rows = array_column($this->suggestions->read($query), null, 'id');
            $current = $rows[$input->id] ?? null;
            $this->assertSaveAmendable($current, $input, $expectedVersion, $author);

            $this->validateDraft($input, $state->content->toArray(), $rows);

            return $this->persistDraft($conn, $query, $state->documentId, $state->generation, $state->position, $state->schemaVersion, $author, $input, $current, $rows);
        });
    }

    public function accept(int|string $nodeId, string $id, int $generation, int $expectedVersion, ?string $commandId = null): TextSuggestion
    {
        return $this->decide($nodeId, $generation, [$id => $expectedVersion], 'accepted', commandId: $commandId)[0];
    }

    public function reject(int|string $nodeId, string $id, int $generation, int $expectedVersion, ?string $commandId = null): TextSuggestion
    {
        return $this->decide($nodeId, $generation, [$id => $expectedVersion], 'rejected', commandId: $commandId)[0];
    }

    /**
     * @param  array<string, int>  $expectedVersions
     * @return list<TextSuggestion>
     */
    public function acceptGroup(int|string $nodeId, string $groupId, int $generation, array $expectedVersions, ?string $commandId = null): array
    {
        return $this->decide($nodeId, $generation, $expectedVersions, 'accepted', $groupId, $commandId);
    }

    /**
     * @param  array<string, int>  $expectedVersions
     * @return list<TextSuggestion>
     */
    public function rejectGroup(int|string $nodeId, string $groupId, int $generation, array $expectedVersions, ?string $commandId = null): array
    {
        return $this->decide($nodeId, $generation, $expectedVersions, 'rejected', $groupId, $commandId);
    }

    private function saveViaJournal(
        string $nodeId,
        TextSuggestionInput|StructuralSuggestionInput $input,
        int $expectedVersion,
    ): TextSuggestion {
        $author = $this->subject();
        $this->capabilitiesResolver->assertInputSupported($input);

        /** @var TextSuggestion $saved */
        $saved = $this->runner->write(
            new OperationSpec('review.suggestions.save', new ModuleId('review')),
            $this->context->operation,
            function () use ($nodeId, $input, $expectedVersion, $author): TextSuggestion {
                $drive = $this->driveWithoutIdempotency();
                $nodeSnap = $drive->nodes()->find($nodeId);
                $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
                if (($abilities[Ability::Edit->value] ?? false) !== true) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $this->documents->in($drive)->lockForRelatedWrite($nodeId);
                $docMeta = $this->lookup->documentForNode($nodeId);
                if ($docMeta['write_mode'] !== 'journal') {
                    throw SuggestionConflictException::because('Document write mode changed from journal.', 'write_mode_changed');
                }
                $this->capabilitiesResolver->assertDocumentSupported($docMeta['type'], $docMeta['schema_version']);

                if ($input->generation !== $docMeta['collab_epoch'] || $input->schemaVersion !== $docMeta['schema_version']) {
                    throw new SuggestionConflictException('The suggestion generation or schema version is stale.');
                }

                $documentId = (string) $docMeta['id'];
                $logSnapshot = $this->journalBridge->snapshot($nodeId, $input->generation);
                $headSeq = $logSnapshot !== null ? $logSnapshot->headSeq : 0;

                // Validate basePosition against journal head (or unseeded initial version when headSeq === 0).
                if ($headSeq === 0) {
                    if ($input->basePosition !== 0 && $input->basePosition !== $docMeta['state_version']) {
                        throw new SuggestionConflictException('The journal suggestion base position is stale.');
                    }
                } elseif ($input->basePosition !== $headSeq) {
                    throw new SuggestionConflictException('The journal suggestion base position is stale.');
                }

                $conn = $this->lookup->connection();
                $this->syncPendingJournalLocked($conn, $nodeId, $documentId, $input->generation);

                $query = $this->suggestions->forDocument($conn, $documentId, $input->generation);
                $rows = array_column($this->suggestions->read($query), null, 'id');
                $current = $rows[$input->id] ?? null;
                $this->assertSaveAmendable($current, $input, $expectedVersion, $author);

                $settledDoc = ($logSnapshot !== null && $logSnapshot->tail === [] && is_array($logSnapshot->content))
                    ? $logSnapshot->content
                    : null;
                $this->validateDraft($input, $settledDoc, $rows);

                return $this->persistDraft($conn, $query, $documentId, $input->generation, $input->basePosition, $docMeta['schema_version'], $author, $input, $current, $rows);
            },
        );

        return $saved;
    }

    /**
     * @param  array<string, int>  $versions
     * @return list<TextSuggestion>
     */
    private function decide(
        int|string $nodeId,
        int $generation,
        array $versions,
        string $decision,
        ?string $groupId = null,
        ?string $commandId = null,
    ): array {
        if ($versions === []) {
            throw new SuggestionConflictException('Choose at least one suggestion.');
        }
        foreach ($versions as $id => $version) {
            $this->assertUuid($id);
            if ($version < 1) {
                throw new SuggestionConflictException('A decision requires the current suggestion version.');
            }
        }
        if ($groupId !== null) {
            $this->assertUuid($groupId);
        }

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $nodeSnap = $drive->nodes()->find($nodeId);
        $docMeta = $this->lookup->documentForNode($nodeSnap->id);

        if ($docMeta['write_mode'] === 'journal') {
            return $this->decideViaJournal($nodeSnap->id, $generation, $versions, $decision, $groupId, $commandId);
        }

        return $this->atomic->run($this->context, $nodeSnap->id, function (LockedDocumentMutation $locked) use ($generation, $versions, $groupId, $decision): array {
            $state = $locked->snapshot();
            if ($state->generation !== $generation) {
                throw new SuggestionConflictException('The suggestion generation is stale.');
            }
            $subject = $this->subject();
            $query = $this->suggestions->forDocument($locked->connection(), $state->documentId, $generation);
            $all = array_column($this->suggestions->read($query), null, 'id');
            $selected = array_intersect_key($all, $versions);
            if (count($selected) !== count($versions)
                || ($groupId !== null && array_keys(array_filter($all, static fn (TextSuggestion $row): bool => $row->groupId === $groupId)) !== array_keys($selected))) {
                throw new SuggestionConflictException('The selected suggestions or group membership have changed.');
            }
            if ($this->isReplay($selected, $versions, $decision, $subject)) {
                return array_values($selected);
            }

            $this->assertSnapshot($state);
            $this->capabilitiesResolver->assertDocumentSupported($state->type, $state->schemaVersion);
            foreach ($selected as $id => $row) {
                if ($row->state !== 'open' || $row->version !== $versions[$id] || $row->proposalId !== null) {
                    throw new SuggestionConflictException('The selection is stale or requires a proposal decision.');
                }
            }
            $others = array_diff_key($all, $selected);
            $receipt = $decision === 'accepted' ? (string) Str::uuid7() : null;
            if ($decision === 'accepted') {
                $this->acceptSnapshot($locked, $state, $query, $selected, $others);
            } else {
                $supersededIds = $this->rejectDescendants($query, $selected, $others, $subject);
                $this->clearResolvedConflicts($query, $all, array_values(array_unique([...array_keys($selected), ...$supersededIds])));
            }
            foreach ($selected as $row) {
                $this->recordDecision($query, $row, $decision, $subject, $receipt);
            }

            return $this->suggestions->read($query->whereIn('id', array_keys($selected)));
        });
    }

    /**
     * @param  array<string, int>  $versions
     * @return list<TextSuggestion>
     */
    private function decideViaJournal(
        string $nodeId,
        int $generation,
        array $versions,
        string $decision,
        ?string $groupId,
        ?string $commandId,
    ): array {
        $spec = match ([$decision, $groupId !== null]) {
            ['accepted', false] => new OperationSpec('review.suggestions.accept', new ModuleId('review')),
            ['rejected', false] => new OperationSpec('review.suggestions.reject', new ModuleId('review')),
            ['accepted', true] => new OperationSpec('review.suggestions.accept_group', new ModuleId('review')),
            default => new OperationSpec('review.suggestions.reject_group', new ModuleId('review')),
        };

        $subject = $this->subject();
        $resolvedCommandId = $this->resolveCommandId($commandId, $decision, $generation, $versions, $groupId);

        /** @var list<TextSuggestion> $outcome */
        $outcome = $this->runner->write(
            $spec,
            $this->context->operation,
            function () use ($nodeId, $generation, $versions, $decision, $groupId, $subject, $resolvedCommandId): array {
                $drive = $this->driveWithoutIdempotency();
                $nodeSnap = $drive->nodes()->find($nodeId);
                $abilities = $drive->nodes()->abilities($nodeSnap)->flags;
                if (($abilities[Ability::Edit->value] ?? false) !== true) {
                    throw PermissionDeniedException::forAbility(Ability::Edit->value);
                }

                $this->documents->in($drive)->lockForRelatedWrite($nodeId);
                $meta = $this->lookup->documentForNode($nodeId);
                if ($meta['write_mode'] !== 'journal') {
                    throw SuggestionConflictException::because('Document write mode changed from journal.', 'write_mode_changed');
                }
                if ($meta['collab_epoch'] !== $generation) {
                    throw new SuggestionConflictException('The suggestion generation is stale.');
                }
                $this->capabilitiesResolver->assertDocumentSupported($meta['type'], $meta['schema_version']);

                $conn = $this->lookup->connection();
                $documentId = (string) $meta['id'];
                $this->syncPendingJournalLocked($conn, $nodeId, $documentId, $generation);

                $query = $this->suggestions->forDocument($conn, $documentId, $generation);
                $all = array_column($this->suggestions->read($query), null, 'id');
                $selected = array_intersect_key($all, $versions);
                if (count($selected) !== count($versions)
                    || ($groupId !== null && array_keys(array_filter($all, static fn (TextSuggestion $row): bool => $row->groupId === $groupId)) !== array_keys($selected))) {
                    throw new SuggestionConflictException('The selected suggestions or group membership have changed.');
                }

                if ($this->isReplay($selected, $versions, $decision, $subject)) {
                    return array_values($selected);
                }

                // Check if caller is replaying an already-registered running journal command.
                $actor = $this->journalBridge->actor($this->context, $nodeId, $subject);
                $firstSelected = reset($selected);
                $pendingCmd = $firstSelected !== false ? $firstSelected->journalCommandId : null;
                if ($pendingCmd !== null && $pendingCmd === $resolvedCommandId) {
                    $status = $this->journalBridge->status($actor, $pendingCmd);
                    if (is_array($status) && ($status['state'] ?? null) === 'running') {
                        return array_values($selected);
                    }
                }

                foreach ($selected as $id => $row) {
                    if ($row->state !== 'open' || $row->version !== $versions[$id] || $row->proposalId !== null) {
                        throw new SuggestionConflictException('The selection is stale or requires a proposal decision.');
                    }
                }

                $logSnapshot = $this->journalBridge->snapshot($nodeId, $generation);
                $headSeq = $logSnapshot !== null ? $logSnapshot->headSeq : 0;

                if ($decision === 'accepted') {
                    $this->assertSelectionAcceptableBeforeWrite($selected, $meta['schema_version'], $logSnapshot?->tail === [] ? $logSnapshot->content : null);
                }

                $baseSeqs = array_map(static fn (TextSuggestion $row): int => $row->basePosition, array_values($selected));
                $maxBase = $baseSeqs !== [] ? max($baseSeqs) : 0;
                $readPosition = $headSeq === 0 && $maxBase === $meta['state_version'] ? 0 : $maxBase;

                if ($headSeq > 0 && $maxBase > $headSeq) {
                    throw new SuggestionConflictException('The suggestion base position exceeds the collaboration head.');
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
                    'atomic_effects' => $decision === 'accepted' ? $this->buildAtomicEffects($selected) : [],
                ];
                $digestHex = SuggestionJournalBridge::canonicalDigest($intent);

                $record = $this->journalBridge->register(
                    actor: $actor,
                    commandId: $resolvedCommandId,
                    proposalUuid: $proposalUuid,
                    digestHex: $digestHex,
                    generation: $generation,
                    schemaVersion: $meta['schema_version'],
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
                    $this->finalizeJournalOutcome($conn, $query, $all, array_keys($selected), $decision, $subject, $resolvedCommandId, $record['receipt_id'], $toSeq);

                    return $this->suggestions->read($this->suggestions->forDocument($conn, $documentId, $generation)->whereIn('id', array_keys($selected)));
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

                return $this->suggestions->read($this->suggestions->forDocument($conn, $documentId, $generation)->whereIn('id', array_keys($selected)));
            },
        );

        return $outcome;
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, mixed>|null  $settledDoc
     */
    private function assertSelectionAcceptableBeforeWrite(array $selected, int $schemaVersion, ?array $settledDoc): void
    {
        $sorted = array_values($selected);
        foreach ($sorted as $row) {
            if ($row->conflicted || $row->parentId !== null || $row->schemaVersion !== $schemaVersion) {
                throw new SuggestionConflictException('Refresh the target or resolve its conflict or parent before accepting.');
            }
            if ($row->isStructural()) {
                $input = new StructuralSuggestionInput(
                    id: $row->id,
                    groupId: $row->groupId,
                    generation: $row->generation,
                    basePosition: $row->basePosition,
                    schemaVersion: $row->schemaVersion,
                    kind: $row->kind,
                    targetBlockId: $row->targetBlockId,
                    selector: $row->targetSelector,
                    payload: $row->payload,
                    proposalId: $row->proposalId,
                    parentId: $row->parentId,
                );
                $this->capabilitiesResolver->assertInputSupported($input);
            }
        }

        $count = count($sorted);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->overlapDetector->overlaps($sorted[$i], $sorted[$j])) {
                    throw new SuggestionConflictException('A decision cannot accept overlapping edits together.');
                }
            }
        }

        if ($settledDoc !== null) {
            $doc = $settledDoc;
            usort($sorted, static function (TextSuggestion $a, TextSuggestion $b): int {
                $blockOrder = $a->targetBlockId <=> $b->targetBlockId;

                return $blockOrder !== 0 ? $blockOrder : ([$b->from, $b->to] <=> [$a->from, $a->to]);
            });
            foreach ($sorted as $row) {
                $doc = $this->structuralEditor->apply($doc, $row)['doc'];
            }
        }
    }

    private function syncPendingJournalState(string $nodeId, string $documentId, int $generation): void
    {
        $conn = $this->lookup->connection();
        $openWithPending = $this->suggestions->forDocument($conn, $documentId, $generation)
            ->where('state', 'open')
            ->whereNotNull('provenance_json')
            ->exists();
        if (! $openWithPending) {
            return;
        }

        $this->database->transaction(function () use ($nodeId, $documentId, $generation): void {
            $this->syncPendingJournalLocked($this->lookup->connection(), $nodeId, $documentId, $generation);
        });
    }

    private function syncPendingJournalLocked(Connection $conn, string $nodeId, string $documentId, int $generation): void
    {
        $query = $this->suggestions->forDocument($conn, $documentId, $generation);
        $all = array_column($this->suggestions->read($query), null, 'id');

        /** @var array<string, list<string>> $groupsByCommand */
        $groupsByCommand = [];
        /** @var array<string, array<string, mixed>> $metaByCommand */
        $metaByCommand = [];

        foreach ($all as $row) {
            if ($row->state !== 'open') {
                continue;
            }
            $prov = $this->suggestions->readProvenance($conn, $row->id);
            $pending = is_array($prov['pending_journal'] ?? null) ? $prov['pending_journal'] : null;
            $cmdId = is_array($pending) && is_string($pending['command_id'] ?? null) ? $pending['command_id'] : null;
            if ($cmdId === null) {
                continue;
            }
            $groupsByCommand[$cmdId][] = $row->id;
            $metaByCommand[$cmdId] ??= $pending;
        }

        foreach ($groupsByCommand as $cmdId => $rowIds) {
            $pendingMeta = $metaByCommand[$cmdId];
            $decidedBy = is_string($pendingMeta['decided_by_subject'] ?? null) ? $pendingMeta['decided_by_subject'] : $this->subject();
            $decision = ($pendingMeta['decision'] ?? null) === 'rejected' ? 'rejected' : 'accepted';

            $actor = $this->journalBridge->actor($this->context, $nodeId, $decidedBy);
            $record = $this->journalBridge->status($actor, $cmdId);
            if (! is_array($record)) {
                continue;
            }
            $state = $record['state'] ?? null;
            if ($state === 'committed' && is_string($record['receipt_id'] ?? null)) {
                $toSeq = is_int($record['to_seq'] ?? null) ? $record['to_seq'] : null;
                $freshAll = array_column($this->suggestions->read($this->suggestions->forDocument($conn, $documentId, $generation)), null, 'id');
                $this->finalizeJournalOutcome(
                    $conn,
                    $this->suggestions->forDocument($conn, $documentId, $generation),
                    $freshAll,
                    $rowIds,
                    $decision,
                    $decidedBy,
                    $cmdId,
                    $record['receipt_id'],
                    $toSeq,
                );
            } elseif ($state === 'rejected') {
                $rejCode = is_string($record['rejection_code'] ?? null) ? $record['rejection_code'] : 'journal_rejected';
                foreach ($rowIds as $rid) {
                    $row = $all[$rid] ?? null;
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
            $destroyed = [];
            foreach ($selected as $row) {
                if ($row->kind === SuggestionKind::BlockJoin->value && is_string($row->payload['second_block_id'] ?? null)) {
                    $destroyed[] = $row->payload['second_block_id'];
                }
            }
            foreach ($selected as $row) {
                $prov = $this->suggestions->readProvenance($conn, $row->id) ?? [];
                unset($prov['pending_journal']);
                $prov['schema'] = 1;
                $prov['journal_command_id'] = $commandId;
                $prov['journal_state'] = 'committed';
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
            foreach ($others as $other) {
                if ($other->state === 'open') {
                    $this->rebaseAfterAccept($query, $other, array_values($selected), $newPosition, $destroyed);
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

    private function assertSaveAmendable(
        ?TextSuggestion $current,
        TextSuggestionInput|StructuralSuggestionInput $input,
        int $expectedVersion,
        string $author,
    ): void {
        $isStructuralInput = $input instanceof StructuralSuggestionInput;
        if (($current->version ?? 0) !== $expectedVersion || ($current !== null && (
            $current->state !== 'open'
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
                $row->state !== 'open' || $row->authorSubject !== $author || $row->proposalId !== $input->proposalId
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
            if ($parent === null || $parent->state !== 'open' || $parent->proposalId !== null || $parent->targetBlockId !== $input->targetBlockId
                || $parent->conflicted || $parent->basePosition !== $input->basePosition || $parent->schemaVersion !== $input->schemaVersion
                || $parent->after === '' || SnapshotTextEdit::slice($parent->after, $input->from, $input->to) !== $input->before) {
                throw new SuggestionConflictException('The parent insertion is unavailable at the supplied text range.');
            }

            return;
        }

        if ($doc !== null) {
            if ($input instanceof StructuralSuggestionInput) {
                $this->structuralEditor->assertDocCandidateTextualIfTableTargeted($doc, $input);
            }
            $this->structuralEditor->apply($doc, $input);
        }
    }

    /**
     * @param  array<string, TextSuggestion>  $rows
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
    ): TextSuggestion {
        $conflicted = false;
        $conflictReason = null;
        $conflictWithId = null;

        // Overlap conflict policy v1: an open overlapping suggestion where either side is structural
        // keeps the new suggestion in `open` with an explicit conflict while preserving original author.
        foreach ($rows as $existing) {
            if ($existing->id === $input->id || $existing->state !== 'open') {
                continue;
            }
            $eitherStructural = $input instanceof StructuralSuggestionInput || $existing->isStructural();
            if (! $eitherStructural) {
                continue;
            }
            if ($this->overlapDetector->overlaps($input, $existing)) {
                $conflicted = true;
                $conflictReason = 'overlap_conflict';
                $conflictWithId = $existing->id;
                break;
            }
        }

        $extraSelector = $input instanceof StructuralSuggestionInput ? $input->selector : [];
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
        }

        return $this->suggestions->read($this->suggestions->forDocument($conn, $documentId, $generation)->where('id', $input->id))[0];
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
            if ($row->conflicted || $row->parentId !== null || $row->basePosition !== $state->position || $row->schemaVersion !== $state->schemaVersion) {
                throw new SuggestionConflictException('Refresh the target or resolve its parent or conflict before accepting.');
            }
            if ($row->isStructural()) {
                $input = new StructuralSuggestionInput(
                    id: $row->id,
                    groupId: $row->groupId,
                    generation: $row->generation,
                    basePosition: $row->basePosition,
                    schemaVersion: $row->schemaVersion,
                    kind: $row->kind,
                    targetBlockId: $row->targetBlockId,
                    selector: $row->targetSelector,
                    payload: $row->payload,
                    proposalId: $row->proposalId,
                    parentId: $row->parentId,
                );
                $this->capabilitiesResolver->assertInputSupported($input);
            }
        }

        $ordered = array_values($selected);
        $count = count($ordered);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->overlapDetector->overlaps($ordered[$i], $ordered[$j])) {
                    throw new SuggestionConflictException('A decision cannot accept overlapping edits together.');
                }
            }
        }

        usort($ordered, static function (TextSuggestion $a, TextSuggestion $b): int {
            $blockOrder = $a->targetBlockId <=> $b->targetBlockId;

            return $blockOrder !== 0 ? $blockOrder : ([$b->from, $b->to] <=> [$a->from, $a->to]);
        });

        $doc = $state->content->toArray();
        $destroyedBlockIds = [];
        foreach ($ordered as $row) {
            $applied = $this->structuralEditor->apply($doc, $row);
            $doc = $applied['doc'];
            $destroyedBlockIds = [...$destroyedBlockIds, ...$applied['destroyedBlockIds']];
        }

        $codec = $this->types->get($state->type)->codec();
        $result = $locked->replace($codec->decode($doc, $state->schemaVersion), $state->position);
        $destroyedBlockIds = array_values(array_unique($destroyedBlockIds));

        foreach ($others as $row) {
            if ($row->state === 'open' && $row->basePosition === $state->position && $row->schemaVersion === $state->schemaVersion) {
                $this->rebaseAfterAccept($query, $row, $ordered, $result->position, $destroyedBlockIds);
            }
        }
    }

    /**
     * @param  list<TextSuggestion>  $selected
     * @param  list<string>  $destroyedBlockIds
     */
    private function rebaseAfterAccept(Builder $query, TextSuggestion $row, array $selected, int $position, array $destroyedBlockIds): void
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
                if ($this->overlapDetector->overlaps($row, $edit, $destroyedBlockIds)) {
                    $conflicted = true;
                    $conflictReason = 'overlap_conflict';
                    $conflictWithId = $edit->id;
                } elseif (! $row->isStructural() && ! $edit->isStructural()
                    && $edit->targetBlockId === $row->targetBlockId && $edit->to <= $from) {
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
     */
    private function clearResolvedConflicts(Builder $query, array $all, array $rejectedIds): void
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

            $nextConflictWith = null;
            foreach ($remainingOpen as $other) {
                if ($other->id !== $open->id
                    && ($open->isStructural() || $other->isStructural())
                    && $this->overlapDetector->overlaps($open, $other)) {
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
        foreach ($selected as $row) {
            if (! $row->isStructural()) {
                $effects[] = [
                    'effect' => 'replace_exact',
                    'block_id' => $row->targetBlockId,
                    'expected' => $row->before,
                    'replacement' => $row->after,
                    'from_utf16' => $row->from,
                    'to_utf16' => $row->to,
                ];
            } else {
                $effects[] = [
                    'effect' => $row->kind,
                    'block_id' => $row->targetBlockId,
                    'selector' => $row->targetSelector,
                    'payload' => $row->payload,
                ];
            }
        }

        return $effects;
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
