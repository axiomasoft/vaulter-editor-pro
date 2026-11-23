<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Operations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Repositories\ReviewLookup;
use Vaulter\EditorPro\Review\Repositories\SuggestionRepository;
use Vaulter\EditorPro\Review\Suggestions\SnapshotTextEdit;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Exceptions\Access\MissingActorException;

/** Versioned text review for snapshot documents. */
#[Api(since: '1.0')]
final readonly class SuggestionOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private AtomicDocumentMutation $atomic,
        private SuggestionRepository $suggestions,
        private ReviewLookup $lookup,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private DocumentTypeRegistry $types,
    ) {}

    /** @return list<TextSuggestion> */
    public function list(int|string $nodeId, ?string $proposalId = null): array
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->context);
        $snapshot = $this->documents->in($drive)->content($nodeId);
        $query = $this->suggestions->forDocument($this->lookup->connection(), $snapshot->documentId, $snapshot->epoch);
        if ($proposalId !== null) {
            $this->assertUuid($proposalId);
            $query->where('proposal_id', $proposalId);
        }

        return $this->suggestions->read($query);
    }

    /** Create with version 0, or amend/retarget an open suggestion as its original author. */
    public function save(int|string $nodeId, TextSuggestionInput $input, int $expectedVersion = 0): TextSuggestion
    {
        return $this->atomic->run($this->context, $nodeId, function (LockedDocumentMutation $locked) use ($input, $expectedVersion): TextSuggestion {
            $state = $locked->snapshot();
            $this->assertSnapshot($state);
            if ($input->generation !== $state->generation || $input->basePosition !== $state->position || $input->schemaVersion !== $state->schemaVersion) {
                throw new SuggestionConflictException('The suggestion base is stale.');
            }

            $author = $this->subject();
            $query = $this->suggestions->forDocument($locked->connection(), $state->documentId, $state->generation);
            $rows = array_column($this->suggestions->read($query), null, 'id');
            $current = $rows[$input->id] ?? null;
            if (($current->version ?? 0) !== $expectedVersion || ($current !== null && (
                $current->state !== 'open' || $current->authorSubject !== $author || $current->groupId !== $input->groupId
                || $current->proposalId !== $input->proposalId || $current->parentId !== $input->parentId
            ))) {
                throw new SuggestionConflictException('The suggestion is unavailable at the supplied version.');
            }
            $this->validateDraft($input, $state, $rows, $author);
            $values = [
                'base_position' => $state->position,
                'kind' => $input->kind,
                'target_block_id' => $input->targetBlockId,
                'target_selector' => SuggestionRepository::selector($input->from, $input->to, $input->parentId),
                'payload' => json_encode(['before' => $input->before, 'after' => $input->after], JSON_THROW_ON_ERROR),
            ];
            if ($current === null) {
                $this->suggestions->query($locked->connection())->insert($values + [
                    'id' => $input->id,
                    'tenant_id' => $this->lookup->normalizeTenantId($this->context->operation->tenant->id),
                    'document_id' => $state->documentId,
                    'group_id' => $input->groupId,
                    'proposal_id' => $input->proposalId,
                    'generation' => $state->generation,
                    'schema_version' => $state->schemaVersion,
                    'author_subject' => $author,
                ]);
            } else {
                $this->suggestions->update($query, $current, $values + ['schema_version' => $state->schemaVersion]);
            }

            return $this->suggestions->read($query->where('id', $input->id))[0];
        });
    }

    public function accept(int|string $nodeId, string $id, int $generation, int $expectedVersion): TextSuggestion
    {
        return $this->decide($nodeId, $generation, [$id => $expectedVersion], 'accepted')[0];
    }

    public function reject(int|string $nodeId, string $id, int $generation, int $expectedVersion): TextSuggestion
    {
        return $this->decide($nodeId, $generation, [$id => $expectedVersion], 'rejected')[0];
    }

    /** @param array<string, int> $expectedVersions
     * @return list<TextSuggestion>
     */
    public function acceptGroup(int|string $nodeId, string $groupId, int $generation, array $expectedVersions): array
    {
        return $this->decide($nodeId, $generation, $expectedVersions, 'accepted', $groupId);
    }

    /** @param array<string, int> $expectedVersions
     * @return list<TextSuggestion>
     */
    public function rejectGroup(int|string $nodeId, string $groupId, int $generation, array $expectedVersions): array
    {
        return $this->decide($nodeId, $generation, $expectedVersions, 'rejected', $groupId);
    }

    /** @param array<string, int> $versions
     * @return list<TextSuggestion>
     */
    private function decide(int|string $nodeId, int $generation, array $versions, string $decision, ?string $groupId = null): array
    {
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

        return $this->atomic->run($this->context, $nodeId, function (LockedDocumentMutation $locked) use ($generation, $versions, $groupId, $decision): array {
            $state = $locked->snapshot();
            if ($state->generation !== $generation) {
                throw new SuggestionConflictException('The suggestion generation is stale.');
            }
            $subject = $this->subject();
            $query = $this->suggestions->forDocument($locked->connection(), $state->documentId, $generation);
            $all = array_column($this->suggestions->read($query), null, 'id');
            $selected = array_intersect_key($all, $versions);
            if (count($selected) !== count($versions)
                || ($groupId !== null && array_keys(array_filter($all, fn (TextSuggestion $row): bool => $row->groupId === $groupId)) !== array_keys($selected))) {
                throw new SuggestionConflictException('The selected suggestions or group membership have changed.');
            }
            if ($this->isReplay($selected, $versions, $decision, $subject)) {
                return array_values($selected);
            }

            $this->assertSnapshot($state);
            foreach ($selected as $id => $row) {
                if ($row->state !== 'open' || $row->version !== $versions[$id] || $row->proposalId !== null) {
                    throw new SuggestionConflictException('The selection is stale or requires a proposal decision.');
                }
            }
            $others = array_diff_key($all, $selected);
            $receipt = $decision === 'accepted' ? (string) Str::uuid7() : null;
            if ($decision === 'accepted') {
                $this->acceptText($locked, $state, $query, $selected, $others);
            } else {
                $this->rejectDescendants($query, $selected, $others, $subject);
            }
            foreach ($selected as $row) {
                $this->recordDecision($query, $row, $decision, $subject, $receipt);
            }

            return $this->suggestions->read($query->whereIn('id', array_keys($selected)));
        });
    }

    /** @param array<string, TextSuggestion> $rows */
    private function validateDraft(TextSuggestionInput $input, DocumentMutationState $state, array $rows, string $author): void
    {
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
        if ($input->parentId === null) {
            new SnapshotTextEdit($input->targetBlockId, $input->from, $input->to, $input->before, $input->after)
                ->apply($state->content->toArray());

            return;
        }

        $parent = $rows[$input->parentId] ?? null;
        if ($parent === null || $parent->state !== 'open' || $parent->proposalId !== null || $parent->targetBlockId !== $input->targetBlockId
            || $parent->conflicted || $parent->basePosition !== $input->basePosition || $parent->schemaVersion !== $input->schemaVersion
            || $parent->after === '' || SnapshotTextEdit::slice($parent->after, $input->from, $input->to) !== $input->before) {
            throw new SuggestionConflictException('The parent insertion is unavailable at the supplied text range.');
        }
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
                || $row->version - 1 !== $versions[$id] || $row->receiptId !== $receipt) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, TextSuggestion>  $others
     */
    private function acceptText(LockedDocumentMutation $locked, DocumentMutationState $state, Builder $query, array $selected, array $others): void
    {
        foreach ($selected as $row) {
            if ($row->conflicted || $row->parentId !== null || $row->basePosition !== $state->position || $row->schemaVersion !== $state->schemaVersion) {
                throw new SuggestionConflictException('Refresh the target or resolve its parent before accepting.');
            }
        }
        usort($selected, static function (TextSuggestion $a, TextSuggestion $b): int {
            $blockOrder = $a->targetBlockId <=> $b->targetBlockId;

            return $blockOrder !== 0 ? $blockOrder : ([$b->from, $b->to] <=> [$a->from, $a->to]);
        });
        $doc = $state->content->toArray();
        $previous = null;
        foreach ($selected as $row) {
            if ($previous !== null && $row->targetBlockId === $previous->targetBlockId && $this->overlaps($row->from, $row->to, $previous)) {
                throw new SuggestionConflictException('A decision cannot accept overlapping edits together.');
            }
            $doc = new SnapshotTextEdit($row->targetBlockId, $row->from, $row->to, $row->before, $row->after)->apply($doc);
            $previous = $row;
        }
        $codec = $this->types->get($state->type)->codec();
        $result = $locked->replace($codec->decode($doc, $state->schemaVersion), $state->position);
        foreach ($others as $row) {
            if ($row->state === 'open' && $row->basePosition === $state->position && $row->schemaVersion === $state->schemaVersion) {
                $this->rebase($query, $row, $selected, $result->position);
            }
        }
    }

    /** @param list<TextSuggestion> $selected */
    private function rebase(Builder $query, TextSuggestion $row, array $selected, int $position): void
    {
        $from = $row->from;
        $to = $row->to;
        $parent = $row->parentId;
        $conflicted = $row->conflicted;
        foreach ($selected as $edit) {
            if ($edit->targetBlockId !== $row->targetBlockId) {
                continue;
            }
            if ($parent === $edit->id) {
                $parent = null;
                $from += $edit->from;
                $to += $edit->from;

                continue;
            }
            if ($parent === null && ! $conflicted) {
                if ($this->overlaps($from, $to, $edit)) {
                    $conflicted = true;
                } elseif ($edit->to <= $from) {
                    $shift = SnapshotAnchorResolver::utf16Length($edit->after) - ($edit->to - $edit->from);
                    $from += $shift;
                    $to += $shift;
                }
            }
        }
        $this->suggestions->update($query, $row, [
            'base_position' => $position,
            'target_selector' => SuggestionRepository::selector($from, $to, $parent, $conflicted),
        ]);
    }

    private function overlaps(int $from, int $to, TextSuggestion $edit): bool
    {
        return ($from < $edit->to && $edit->from < $to) || ($from === $to && $edit->from === $edit->to && $from === $edit->from);
    }

    /**
     * @param  array<string, TextSuggestion>  $selected
     * @param  array<string, TextSuggestion>  $others
     */
    private function rejectDescendants(Builder $query, array $selected, array $others, string $subject): void
    {
        $children = [];
        foreach ($others as $row) {
            if ($row->state === 'open' && $row->parentId !== null) {
                $children[$row->parentId][] = $row;
            }
        }
        $frontier = array_keys($selected);
        while (($parent = array_pop($frontier)) !== null) {
            foreach ($children[$parent] ?? [] as $child) {
                $this->recordDecision($query, $child, 'superseded', $subject, null);
                $frontier[] = $child->id;
            }
        }
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

    private function assertSnapshot(DocumentMutationState $state): void
    {
        if ($state->writeMode !== 'snapshot' || $state->pending || ! $state->content instanceof TipTapContent) {
            throw new SuggestionConflictException('Text review requires a settled TipTap snapshot.');
        }
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
