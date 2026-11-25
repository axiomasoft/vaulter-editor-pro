<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Anchors;

use Packman\Attributes\Internal;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\SnapshotWriteHook;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Repositories\CommentAnchorRepository;

#[Internal]
final readonly class SnapshotAnchorReconciler implements SnapshotWriteHook
{
    public function __construct(
        private CommentAnchorRepository $anchors,
        private SnapshotAnchorResolver $resolver,
    ) {}

    public function supportsDocumentType(string $type): bool
    {
        return $type === RichTextDocumentType::KEY;
    }

    public function replaceSnapshot(string $documentId, int $generation, DocumentContent $previous, DocumentContent $next): void
    {
        if ($previous instanceof TipTapContent && $next instanceof TipTapContent) {
            $this->reconcile($documentId, $generation, $next->toArray(), $previous->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $next
     * @param  array<string, mixed>|null  $previous
     * @return list<CommentAnchorData>
     */
    public function reconcile(string|int $documentId, int $generation, array $next, ?array $previous): array
    {
        $results = [];
        $anchors = $this->anchors->lockAttachedSnapshots($documentId, $generation);
        if ($anchors === []) {
            return $results;
        }

        // Index both documents once instead of walking them for every anchor.
        $nextBlocks = SnapshotBlockTexts::of($next);
        $previousBlocks = $previous === null ? null : SnapshotBlockTexts::of($previous);
        foreach ($anchors as $anchor) {
            $resolution = $previousBlocks === null
                ? $this->resolver->resolveBlock($anchor, $nextBlocks->text($anchor->blockId))
                : $this->resolver->resolveBlockEdit($anchor, $previousBlocks->text($anchor->blockId), $nextBlocks->text($anchor->blockId));

            if (! $resolution->changed) {
                continue;
            }

            $results[] = $this->anchors->updateWithCas(
                commentId: $anchor->commentId,
                documentId: $documentId,
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
    }
}
