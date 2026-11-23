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
final readonly class SuggestionOverlapDetector
{
    /** @param list<string> $destroyedBlockIds */
    public function overlaps(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $a,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $b,
        array $destroyedBlockIds = [],
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

        $aBlocks = $this->touchedBlocks($a);
        $bBlocks = $this->touchedBlocks($b);
        $sharedBlocks = array_intersect($aBlocks, $bBlocks);
        if ($sharedBlocks === []) {
            if ($a->kind === SuggestionKind::TableChange->value && $b->kind === SuggestionKind::TableChange->value) {
                return $this->tableChangesOverlap($a, $b);
            }
            if ($a->kind === SuggestionKind::ListChange->value && $b->kind === SuggestionKind::ListChange->value) {
                return ($a->payload['list_op'] ?? null) === 'toggle_type'
                    || ($b->payload['list_op'] ?? null) === 'toggle_type';
            }

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

        if ($a->kind === SuggestionKind::ListChange->value && $b->kind === SuggestionKind::ListChange->value) {
            return true;
        }

        if ($a->kind === SuggestionKind::TableChange->value && $b->kind === SuggestionKind::TableChange->value) {
            return $this->tableChangesOverlap($a, $b);
        }

        if (($a->kind === SuggestionKind::TableChange->value && $this->isDestructiveTableOp($a))
            || ($b->kind === SuggestionKind::TableChange->value && $this->isDestructiveTableOp($b))) {
            return true;
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

    private function tableChangesOverlap(
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $a,
        TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $b,
    ): bool {
        if ($a->targetBlockId === $b->targetBlockId) {
            return true;
        }
        $aPayload = $a instanceof TextSuggestionInput ? [] : $a->payload;
        $bPayload = $b instanceof TextSuggestionInput ? [] : $b->payload;
        $aRow = $aPayload['row_index'] ?? null;
        $bRow = $bPayload['row_index'] ?? null;
        $aCol = $aPayload['col_index'] ?? null;
        $bCol = $bPayload['col_index'] ?? null;
        if ($this->isDestructiveTableOp($a) || $this->isDestructiveTableOp($b)) {
            if ($aRow !== null && $bRow !== null && $aRow === $bRow) {
                return true;
            }
            if ($aCol !== null && $bCol !== null && $aCol === $bCol) {
                return true;
            }
        }
        if ($aRow !== null && $bRow !== null && $aRow === $bRow && $aCol !== null && $bCol !== null && $aCol === $bCol) {
            return true;
        }

        return false;
    }

    private function isDestructiveTableOp(TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $item): bool
    {
        $payload = $item instanceof TextSuggestionInput ? [] : $item->payload;
        $op = $payload['table_op'] ?? null;

        return $op === 'delete_row' || $op === 'delete_column';
    }
}
