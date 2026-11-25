<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Suggestions;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;

/**
 * Overlap policy v1 detector across text edits and all 6 structural review profiles.
 */
#[Internal]
final class SuggestionOverlapDetector
{
    /**
     * The document the container index below was built from. Callers compare every pair of a selection against
     * the same document, so the index is built once per document instead of walking the tree twice per pair.
     *
     * @var array<string, mixed>|null
     */
    private ?array $indexedDoc = null;

    /** @var array<string, array<string, string>> container block ID by block ID, per container type list */
    private array $containerIndex = [];

    /**
     * @param  list<string>  $destroyedBlockIds
     * @param  array<string, mixed>|null  $doc
     */
    public function overlaps(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $a,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $b,
        array $destroyedBlockIds = [],
        ?array $doc = null,
    ): bool {
        if ($a->id === $b->id) {
            return false;
        }
        if ($a->parentId === $b->id || $b->parentId === $a->id) {
            return false;
        }

        if ($destroyedBlockIds !== [] && in_array($b->targetBlockId, $destroyedBlockIds, true)) {
            return true;
        }
        if ($destroyedBlockIds !== [] && in_array($a->targetBlockId, $destroyedBlockIds, true)) {
            return true;
        }

        if ($a->parentId !== null || $b->parentId !== null) {
            if ($a->parentId === $b->parentId && $a->parentId !== null) {
                return $this->rangesOverlap($a->from, $a->to, $b->from, $b->to);
            }

            return false;
        }

        if ($a->kind === SuggestionKind::TableChange->value && $b->kind === SuggestionKind::TableChange->value) {
            return $this->tableChangesOverlap($a, $b, $doc);
        }
        if ($a->kind === SuggestionKind::ListChange->value && $b->kind === SuggestionKind::ListChange->value) {
            $container = $this->container($a, $doc);

            return $a->targetBlockId === $b->targetBlockId
                || ($container !== null && $container === $this->container($b, $doc));
        }

        $aBlocks = $this->touchedBlocks($a);
        $bBlocks = $this->touchedBlocks($b);
        $sharedBlocks = array_intersect($aBlocks, $bBlocks);
        if ($sharedBlocks === []) {
            return false;
        }

        // Cross-kind rules when sharing a block ID
        if ($this->isInlineKind($a->kind) && $this->isInlineKind($b->kind)) {
            return $this->rangesOverlap($a->from, $a->to, $b->from, $b->to);
        }

        if ($a->kind === SuggestionKind::AttrChange->value && $b->kind === SuggestionKind::AttrChange->value) {
            $aNode = $a->payload['node_type'] ?? $a->targetSelector['node_type'] ?? null;
            $bNode = $b->payload['node_type'] ?? $b->targetSelector['node_type'] ?? null;
            $aAttr = $a->payload['attr'] ?? $a->targetSelector['attr'] ?? null;
            $bAttr = $b->payload['attr'] ?? $b->targetSelector['attr'] ?? null;
            if ($aNode !== $bNode || $aAttr !== $bAttr) {
                return false;
            }
            if ($aNode === 'link') {
                return $this->rangesOverlap($a->from, $a->to, $b->from, $b->to);
            }

            return true;
        }

        if ($this->isInlineKind($a->kind) && $b->kind === SuggestionKind::AttrChange->value) {
            return $this->inlineVsAttrOverlap($a, $b);
        }
        if ($this->isInlineKind($b->kind) && $a->kind === SuggestionKind::AttrChange->value) {
            return $this->inlineVsAttrOverlap($b, $a);
        }

        if ($a->kind === SuggestionKind::BlockJoin->value || $b->kind === SuggestionKind::BlockJoin->value) {
            $join = $a->kind === SuggestionKind::BlockJoin->value ? $a : $b;
            $other = $join === $a ? $b : $a;

            return $this->joinVsOtherOverlap($join, $other);
        }

        if ($a->kind === SuggestionKind::BlockSplit->value || $b->kind === SuggestionKind::BlockSplit->value) {
            $split = $a->kind === SuggestionKind::BlockSplit->value ? $a : $b;
            $other = $split === $a ? $b : $a;

            return $this->splitVsOtherOverlap($split, $other);
        }

        return true;
    }

    public function rangesOverlap(int $fromA, int $toA, int $fromB, int $toB): bool
    {
        return ($fromA < $toB && $fromB < $toA) || ($fromA === $toA && $fromB === $toB && $fromA === $fromB);
    }

    private function isInlineKind(string $kind): bool
    {
        return in_array($kind, [
            SuggestionKind::Insert->value,
            SuggestionKind::Delete->value,
            SuggestionKind::Replace->value,
            SuggestionKind::MarkChange->value,
        ], true);
    }

    /** @return list<string> */
    private function touchedBlocks(TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $item): array
    {
        $blocks = [$item->targetBlockId];
        $payload = $item instanceof TextSuggestionInput ? [] : $item->payload;
        $second = $payload['second_block_id'] ?? null;
        if (is_string($second) && $second !== '') {
            $blocks[] = $second;
        }

        return $blocks;
    }

    private function inlineVsAttrOverlap(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $inline,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $attrChange,
    ): bool {
        $payload = $attrChange instanceof TextSuggestionInput ? [] : $attrChange->payload;
        if (($payload['node_type'] ?? null) === 'link') {
            return $this->rangesOverlap($inline->from, $inline->to, $attrChange->from, $attrChange->to);
        }

        // Heading level or cell attr edits do not conflict with inline text changes inside that block.
        return false;
    }

    private function splitVsOtherOverlap(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $split,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $other,
    ): bool {
        $payload = $split instanceof TextSuggestionInput ? [] : $split->payload;
        $splitKind = $payload['split_kind'] ?? 'structural';
        $offset = $split->from;

        if ($this->isInlineKind($other->kind)) {
            if ($splitKind === 'insertion') {
                return false;
            }

            // Splitting structurally overlaps edits spanning/at-or-after the split boundary.
            return $other->to > $offset || ($other->from === $other->to && $other->from >= $offset);
        }

        if ($other->kind === SuggestionKind::AttrChange->value) {
            return false;
        }

        return true;
    }

    private function joinVsOtherOverlap(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $join,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $other,
    ): bool {
        $payload = $join instanceof TextSuggestionInput ? [] : $join->payload;
        $secondId = $payload['second_block_id'] ?? null;
        if (is_string($secondId) && $other->targetBlockId === $secondId) {
            return true;
        }
        if ($this->isInlineKind($other->kind) && $other->targetBlockId === $join->targetBlockId) {
            $offset = $payload['join_offset_utf16'] ?? null;
            if (is_int($offset) && $other->to <= $offset) {
                return false;
            }

            return true;
        }

        return true;
    }

    /** @param array<string, mixed>|null $doc */
    private function tableChangesOverlap(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $a,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $b,
        ?array $doc,
    ): bool {
        if ($a->targetBlockId === $b->targetBlockId) {
            return true;
        }

        $aPayload = $a instanceof TextSuggestionInput ? [] : $a->payload;
        $bPayload = $b instanceof TextSuggestionInput ? [] : $b->payload;
        $aOp = $aPayload['table_op'] ?? null;
        $bOp = $bPayload['table_op'] ?? null;
        $container = $this->container($a, $doc);
        if ($container === null || $container !== $this->container($b, $doc)) {
            return false;
        }

        // A row or column insertion/deletion changes the meaning of selectors into its table.
        $indexChanging = ['insert_row', 'insert_column', 'delete_row', 'delete_column'];
        if (in_array($aOp, $indexChanging, true) || in_array($bOp, $indexChanging, true)) {
            return true;
        }

        $row = $aPayload['row_index'] ?? null;

        return $row !== null && $row === ($bPayload['row_index'] ?? null)
            && ($aOp === 'toggle_header_row' || $bOp === 'toggle_header_row'
                || ($aPayload['col_index'] ?? null) === ($bPayload['col_index'] ?? null));
    }

    /** @param array<string, mixed>|null $doc */
    public function container(TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $item, ?array $doc): ?string
    {
        $types = match ($item->kind) {
            SuggestionKind::ListChange->value => ['bulletList', 'orderedList'],
            SuggestionKind::TableChange->value => ['table'],
            default => [],
        };
        if ($types === [] || $item instanceof TextSuggestionInput) {
            return null;
        }
        if ($doc !== null) {
            return $this->findContainer($doc, $item->targetBlockId, $types);
        }

        $id = $item instanceof TextSuggestion ? ($item->targetSelector['container_anchor'] ?? null) : null;

        return is_string($id) ? $id : null;
    }

    /**
     * The block ID of the first container of one of `$types` around `$target` (the container's own first block ID).
     * Document order decides between duplicate block IDs: the first occurrence that sits in such a container wins,
     * and an occurrence nested inside an earlier occurrence of the same ID is never considered.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $types
     */
    private function findContainer(array $doc, string $target, array $types): ?string
    {
        if ($this->indexedDoc !== $doc) {
            $this->indexedDoc = $doc;
            $this->containerIndex = [];
        }
        $key = implode(',', $types);
        if (! isset($this->containerIndex[$key])) {
            $index = [];
            $this->indexContainers($doc, $types, null, [], $index);
            $this->containerIndex[$key] = $index;
        }

        return $this->containerIndex[$key][$target] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $types
     * @param  array<string, true>  $ancestorIds
     * @param  array<string, string>  $index
     */
    private function indexContainers(array $node, array $types, ?string $container, array $ancestorIds, array &$index): void
    {
        if (in_array($node['type'] ?? null, $types, true)) {
            $container = $this->firstBlockId($node);
        }
        $attrs = $node['attrs'] ?? null;
        $id = is_array($attrs) && is_string($attrs['blockId'] ?? null) ? $attrs['blockId'] : null;
        if ($id !== null) {
            if ($container !== null && ! isset($index[$id]) && ! isset($ancestorIds[$id])) {
                $index[$id] = $container;
            }
            $ancestorIds[$id] = true;
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $this->indexContainers($child, $types, $container, $ancestorIds, $index);
            }
        }
    }

    /** @param array<array-key, mixed> $node */
    private function firstBlockId(array $node): ?string
    {
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && is_string($attrs['blockId'] ?? null)) {
            return $attrs['blockId'];
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child) && ($id = $this->firstBlockId($child)) !== null) {
                return $id;
            }
        }

        return null;
    }
}
