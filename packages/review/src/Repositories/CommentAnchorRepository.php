<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Repositories;

use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\EditorPro\Review\Models\DocumentCommentAnchor;
use Vaulter\EditorPro\Review\Support\ReviewTags;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Support\Identity;

/** Persistence repository for `v_document_comment_anchors`. */
#[Internal]
final readonly class CommentAnchorRepository
{
    public function __construct(private ReviewLookup $lookup) {}

    /**
     * @param  array<string, mixed>  $selectorPayload
     */
    public function create(
        string|int $tenantId,
        string|int $commentId,
        string|int $documentId,
        int $generation,
        int $sourcePosition,
        int $schemaVersion,
        string $blockId,
        SelectorKind $selectorKind,
        array $selectorPayload,
        string $quoteText,
        AnchorState $anchorState = AnchorState::Attached,
    ): CommentAnchorData {
        $now = Carbon::now()->toImmutable();
        $anchor = new DocumentCommentAnchor;
        $anchor->forceFill([
            'tenant_id' => $tenantId,
            'comment_id' => $commentId,
            'document_id' => $documentId,
            'generation' => $generation,
            'source_position' => $sourcePosition,
            'schema_version' => $schemaVersion,
            'block_id' => $blockId,
            'selector_kind' => $selectorKind->value,
            'selector_payload' => $selectorPayload,
            'quote_text' => $quoteText,
            'anchor_state' => $anchorState->value,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $anchor->save();

        return $this->toData($anchor->refresh());
    }

    public function findByComment(string|int $commentId): ?CommentAnchorData
    {
        if (! $this->lookup->fitsKey($commentId)) {
            return null;
        }

        $row = DocumentCommentAnchor::query()
            ->where('comment_id', $commentId)
            ->first();

        return $row instanceof DocumentCommentAnchor ? $this->toData($row) : null;
    }

    /**
     * @param  list<string>  $commentIds
     * @return array<string, CommentAnchorData> keyed by commentId
     */
    public function mapByCommentIds(array $commentIds): array
    {
        $validIds = array_values(array_filter(
            $commentIds,
            fn (string $id): bool => $this->lookup->fitsKey($id),
        ));

        if ($validIds === []) {
            return [];
        }

        $mapped = [];
        foreach (DocumentCommentAnchor::query()->whereIn('comment_id', $validIds)->get() as $row) {
            $data = $this->toData($row);
            $mapped[$data->commentId] = $data;
        }

        return $mapped;
    }

    /** @return list<CommentAnchorData> */
    public function listAttachedForDocument(string|int $documentId, ?int $generation = null): array
    {
        if (! $this->lookup->fitsKey($documentId)) {
            return [];
        }

        $query = DocumentCommentAnchor::query()
            ->where('document_id', $documentId)
            ->where('anchor_state', AnchorState::Attached->value);

        if ($generation !== null) {
            $query->where('generation', $generation);
        }

        return array_values($query->get()
            ->map(fn (DocumentCommentAnchor $row): CommentAnchorData => $this->toData($row))
            ->all());
    }

    /**
     * Reanchor or update anchor with explicit row lock + CAS check on `version`.
     *
     * @param  array<string, mixed>  $selectorPayload
     */
    public function updateWithCas(
        string|int $commentId,
        string|int $documentId,
        int $generation,
        int $sourcePosition,
        int $schemaVersion,
        string $blockId,
        SelectorKind $selectorKind,
        array $selectorPayload,
        string $quoteText,
        AnchorState $anchorState,
        ?int $expectedVersion,
    ): CommentAnchorData {
        if (! $this->lookup->fitsKey($commentId) || ! $this->lookup->fitsKey($documentId)) {
            throw StaleWriteException::of(DocumentCommentAnchor::AGGREGATE, (string) $commentId);
        }

        $row = DocumentCommentAnchor::query()
            ->where('comment_id', $commentId)
            ->where('document_id', $documentId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof DocumentCommentAnchor) {
            throw StaleWriteException::of(DocumentCommentAnchor::AGGREGATE, (string) $commentId);
        }

        if ($expectedVersion !== null && $row->version !== $expectedVersion) {
            throw StaleWriteException::of(DocumentCommentAnchor::AGGREGATE, Identity::stringify($row->getKey()));
        }

        $row->forceFill([
            'generation' => $generation,
            'source_position' => $sourcePosition,
            'schema_version' => $schemaVersion,
            'block_id' => $blockId,
            'selector_kind' => $selectorKind->value,
            'selector_payload' => $selectorPayload,
            'quote_text' => $quoteText,
            'anchor_state' => $anchorState->value,
            'version' => $row->version + 1,
            'updated_at' => Carbon::now()->toImmutable(),
        ])->save();

        return $this->toData($row->refresh());
    }

    /**
     * Transition attached anchors from prior generations (< `$currentEpoch`) on restore/replace.
     */
    public function transitionPriorGenerations(
        string|int $documentId,
        int $currentEpoch,
        AnchorState $targetState,
    ): int {
        if (! $this->lookup->fitsKey($documentId)) {
            return 0;
        }

        $now = Carbon::now();

        return $this->lookup->connection()
            ->table(DocumentCommentAnchor::tableName())
            ->where('document_id', $documentId)
            ->where('generation', '<', $currentEpoch)
            ->where('anchor_state', AnchorState::Attached->value)
            ->increment('version', 1, [
                'anchor_state' => $targetState->value,
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    public function toData(DocumentCommentAnchor $row): CommentAnchorData
    {
        $id = Identity::stringify($row->getKey());

        return new CommentAnchorData(
            id: $id,
            commentId: Identity::stringify($row->comment_id),
            documentId: Identity::stringify($row->document_id),
            generation: $row->generation,
            sourcePosition: $row->source_position,
            schemaVersion: $row->schema_version,
            blockId: $row->block_id,
            selectorKind: SelectorKind::from($row->selector_kind),
            selectorPayload: $row->selector_payload,
            quoteText: $row->quote_text,
            anchorState: AnchorState::from($row->anchor_state),
            version: $row->version,
            etag: ReviewTags::anchor($id, $row->version),
            createdAt: $row->created_at->toDateTimeImmutable(),
            updatedAt: $row->updated_at->toDateTimeImmutable(),
        );
    }
}
