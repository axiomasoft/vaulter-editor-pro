<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Suggestions;

use Packman\Attributes\Internal;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Enums\ListOperation;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;
use Vaulter\EditorPro\Review\Enums\TableOperation;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;

/**
 * Applies text and structural suggestions onto TipTap document arrays while enforcing
 * schema, blockId uniqueness, and text-only table invariants before write.
 */
#[Internal]
final readonly class SnapshotStructuralEdit
{
    public function __construct(
        private ReviewCapabilities $capabilities = new ReviewCapabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $doc
     * @return array{doc: array<string, mixed>, destroyedBlockIds: list<string>}
     */
    public function apply(array $doc, TextSuggestion|TextSuggestionInput|StructuralSuggestionInput $suggestion): array
    {
        if ($suggestion instanceof TextSuggestionInput
            || $suggestion->kind === SuggestionKind::Insert->value
            || $suggestion->kind === SuggestionKind::Delete->value
            || $suggestion->kind === SuggestionKind::Replace->value) {
            $updated = new SnapshotTextEdit(
                $suggestion->targetBlockId,
                $suggestion->from,
                $suggestion->to,
                $suggestion->before,
                $suggestion->after,
            )->apply($doc);

            $this->assertValidRichText($updated);

            return ['doc' => $updated, 'destroyedBlockIds' => []];
        }

        if ($suggestion instanceof TextSuggestion) {
            $input = new StructuralSuggestionInput(
                id: $suggestion->id,
                groupId: $suggestion->groupId,
                generation: $suggestion->generation,
                basePosition: $suggestion->basePosition,
                schemaVersion: $suggestion->schemaVersion,
                kind: $suggestion->kind,
                targetBlockId: $suggestion->targetBlockId,
                selector: $suggestion->targetSelector,
                payload: $suggestion->payload,
                proposalId: $suggestion->proposalId,
                parentId: $suggestion->parentId,
            );
        } else {
            $input = $suggestion;
        }

        $this->capabilities->assertInputSupported($input);

        $this->assertDocCandidateTextualIfTableTargeted($doc, $input);
        $destroyed = [];
        $updated = match ($input->kind) {
            SuggestionKind::MarkChange->value => $this->applyMarkChange($doc, $input),
            SuggestionKind::AttrChange->value => $this->applyAttrChange($doc, $input),
            SuggestionKind::BlockSplit->value => $this->applyBlockSplit($doc, $input),
            SuggestionKind::BlockJoin->value => $this->applyBlockJoin($doc, $input, $destroyed),
            SuggestionKind::ListChange->value => $this->applyListChange($doc, $input),
            SuggestionKind::TableChange->value => $this->applyTableChange($doc, $input, $destroyed),
            default => throw UnsupportedSuggestionException::because('Unsupported suggestion kind.', 'unsupported_suggestion_kind'),
        };

        $this->assertValidRichText($updated);

        return ['doc' => $updated, 'destroyedBlockIds' => $destroyed];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyMarkChange(array $doc, StructuralSuggestionInput $input): array
    {
        $markName = self::strVal($input->payload['mark_name'] ?? null);
        $markAttrs = self::stringMapOrEmpty($input->payload['mark_attrs'] ?? null);
        $operation = self::strVal($input->payload['operation'] ?? null, 'added');
        $quoteText = self::strVal($input->payload['quote_text'] ?? null);
        $markSpec = ['type' => $markName] + ($markAttrs === [] ? [] : ['attrs' => $markAttrs]);

        $applied = false;
        $updated = $this->walkReplaceNode($doc, function (array $node) use ($input, $operation, $markName, $markSpec, $quoteText, &$applied): ?array {
            $attrs = $node['attrs'] ?? null;
            if (! is_array($attrs) || ($attrs['blockId'] ?? null) !== $input->targetBlockId) {
                return null;
            }
            $type = $node['type'] ?? null;
            if ($type !== 'paragraph' && $type !== 'heading') {
                throw UnsupportedSuggestionException::because('Mark changes are only supported on paragraph or heading textblocks.', 'unsupported_mark_target');
            }
            $content = $node['content'] ?? [];
            if (! is_array($content) || ! array_is_list($content)) {
                throw new SuggestionConflictException('Invalid target block.');
            }
            $node['content'] = $this->updateMarksInRange(self::nodeList($content), $input->from, $input->to, $quoteText, $operation, $markName, $markSpec);
            $applied = true;

            return $node;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target text block is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $markSpec
     * @return list<array<string, mixed>>
     */
    private function updateMarksInRange(
        array $content,
        int $from,
        int $to,
        string $quoteText,
        string $operation,
        string $markName,
        array $markSpec,
    ): array {
        $result = [];
        $quote = '';
        $position = 0;

        foreach ($content as $inline) {
            $text = ($inline['type'] ?? null) === 'text' && is_string($inline['text'] ?? null) ? $inline['text'] : null;
            $len = $text === null ? 1 : SnapshotAnchorResolver::utf16Length($text);
            $end = $position + $len;

            if ($end <= $from || $position >= $to) {
                $result[] = $inline;
                $position = $end;

                continue;
            }

            if ($text === null) {
                throw new SuggestionConflictException('A mark change cannot span a non-text inline node.');
            }

            $startOffset = max(0, $from - $position);
            $endOffset = min($end, $to) - $position;
            $prefix = SnapshotTextEdit::slice($text, 0, $startOffset);
            $target = SnapshotTextEdit::slice($text, $startOffset, $endOffset);
            $suffix = SnapshotTextEdit::slice($text, $endOffset, $len);
            $quote .= $target;

            if ($prefix !== '') {
                $result[] = [...$inline, 'text' => $prefix];
            }

            $existingMarks = is_array($inline['marks'] ?? null) ? array_values($inline['marks']) : [];
            if ($operation === 'added') {
                $nextMarks = $this->upsertMark($existingMarks, $markName, $markSpec);
            } else {
                [$nextMarks, $removed] = $this->removeMarkByType($existingMarks, $markName);
                if (! $removed) {
                    throw new SuggestionConflictException('The target mark is no longer present in the block.');
                }
            }

            $targetRun = ['type' => 'text', 'text' => $target];
            if ($nextMarks !== []) {
                $targetRun['marks'] = $nextMarks;
            }
            $result[] = $targetRun;

            if ($suffix !== '') {
                $result[] = [...$inline, 'text' => $suffix];
            }

            $position = $end;
        }

        if ($to > $position || $from >= $to || ($quoteText !== '' && $quote !== $quoteText)) {
            throw new SuggestionConflictException('The target text range for mark_change has changed.');
        }

        return $this->mergeAdjacentTextRuns($result);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyAttrChange(array $doc, StructuralSuggestionInput $input): array
    {
        $nodeType = self::strVal($input->payload['node_type'] ?? null);
        $attr = self::strVal($input->payload['attr'] ?? null);
        $oldValue = $input->payload['old_value'] ?? null;
        $newValue = $input->payload['new_value'] ?? null;

        if ($nodeType === 'link') {
            return $this->applyLinkAttrChange($doc, $input, $attr, $oldValue, $newValue);
        }

        if ($nodeType === 'paragraph' || $nodeType === 'heading') {
            $applied = false;
            $updated = $this->walkReplaceNode($doc, static function (array $node) use ($input, $nodeType, $attr, $oldValue, $newValue, &$applied): ?array {
                $attrs = $node['attrs'] ?? null;
                if (! is_array($attrs) || ($attrs['blockId'] ?? null) !== $input->targetBlockId) {
                    return null;
                }
                if (($node['type'] ?? null) !== $nodeType) {
                    throw new SuggestionConflictException('The target block type no longer matches attr_change.');
                }
                $current = $attrs[$attr] ?? null;
                if ($current !== $oldValue) {
                    throw new SuggestionConflictException('The target attribute has changed.');
                }
                if ($newValue === null) {
                    unset($attrs[$attr]);
                } else {
                    $attrs[$attr] = $newValue;
                }
                $node['attrs'] = $attrs;
                $applied = true;

                return $node;
            });
            if (! $applied) {
                throw new SuggestionConflictException('The target block is unavailable.');
            }

            return $updated;
        }

        if ($nodeType === 'orderedList') {
            $applied = false;
            $updated = $this->walkReplaceNode($doc, function (array $node) use ($input, $attr, $oldValue, $newValue, &$applied): ?array {
                if (($node['type'] ?? null) !== 'orderedList' || ! $this->containsBlockId($node, $input->targetBlockId)) {
                    return null;
                }
                $content = $node['content'] ?? [];
                if (is_array($content)) {
                    foreach ($content as $k => $child) {
                        if (! is_array($child)) {
                            continue;
                        }
                        $childNode = self::stringMapOrEmpty($child);
                        if ($this->containsBlockId($childNode, $input->targetBlockId)) {
                            $recursed = $this->walkReplaceNode($childNode, function (array $inner) use ($input, $attr, $oldValue, $newValue, &$applied): ?array {
                                if (($inner['type'] ?? null) !== 'orderedList' || ! $this->containsBlockId($inner, $input->targetBlockId)) {
                                    return null;
                                }
                                $attrs = is_array($inner['attrs'] ?? null) ? $inner['attrs'] : [];
                                if (($attrs[$attr] ?? null) !== $oldValue) {
                                    throw new SuggestionConflictException('The target list attribute has changed.');
                                }
                                if ($newValue === null) {
                                    unset($attrs[$attr]);
                                } else {
                                    $attrs[$attr] = $newValue;
                                }
                                $inner['attrs'] = $attrs;
                                $applied = true;

                                return $inner;
                            });
                            if ($applied) {
                                $content[$k] = $recursed;
                                $node['content'] = $content;

                                return $node;
                            }
                        }
                    }
                }
                $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
                if (($attrs[$attr] ?? null) !== $oldValue) {
                    throw new SuggestionConflictException('The target list attribute has changed.');
                }
                if ($newValue === null) {
                    unset($attrs[$attr]);
                } else {
                    $attrs[$attr] = $newValue;
                }
                $node['attrs'] = $attrs;
                $applied = true;

                return $node;
            });
            if (! $applied) {
                throw new SuggestionConflictException('The target ordered list is unavailable.');
            }

            return $updated;
        }

        // tableCell / tableHeader attribute change
        $applied = false;
        $updated = $this->walkReplaceNode($doc, function (array $node) use ($input, $nodeType, $attr, $oldValue, $newValue, &$applied): ?array {
            if (($node['type'] ?? null) !== 'table' || ! $this->containsBlockId($node, $input->targetBlockId)) {
                return null;
            }
            $this->assertTableIsTextual($node);
            $rawRows = $node['content'] ?? [];
            if (! is_array($rawRows) || ! array_is_list($rawRows)) {
                throw new SuggestionConflictException('Invalid table structure.');
            }
            $rows = self::nodeList($rawRows);
            [$foundRow, $foundCol] = $this->locateCellCoords(
                $rows,
                $input->targetBlockId,
                $input->selectorInt('row_index'),
                $input->selectorInt('col_index'),
            );
            $rowNode = $rows[$foundRow];
            $cells = is_array($rowNode['content'] ?? null) ? self::nodeList($rowNode['content']) : [];
            $cell = $cells[$foundCol] ?? null;
            if (! is_array($cell) || ($cell['type'] ?? null) !== $nodeType) {
                throw new SuggestionConflictException('The target table cell is unavailable.');
            }
            $attrs = is_array($cell['attrs'] ?? null) ? $cell['attrs'] : [];
            if (($attrs[$attr] ?? null) !== $oldValue) {
                throw new SuggestionConflictException('The target table cell attribute has changed.');
            }
            if ($newValue === null) {
                unset($attrs[$attr]);
            } else {
                $attrs[$attr] = $newValue;
            }
            $cell['attrs'] = $attrs;
            $cells[$foundCol] = $cell;
            $rowNode['content'] = $cells;
            $rows[$foundRow] = $rowNode;
            $node['content'] = $rows;
            $applied = true;

            return $node;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target table cell is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyLinkAttrChange(
        array $doc,
        StructuralSuggestionInput $input,
        string $attr,
        mixed $oldValue,
        mixed $newValue,
    ): array {
        $applied = false;
        $updated = $this->walkReplaceNode($doc, function (array $node) use ($input, $attr, $oldValue, $newValue, &$applied): ?array {
            $attrs = $node['attrs'] ?? null;
            if (! is_array($attrs) || ($attrs['blockId'] ?? null) !== $input->targetBlockId) {
                return null;
            }
            $content = is_array($node['content'] ?? null) ? self::nodeList($node['content']) : [];
            if ($input->from >= $input->to || $input->to > $this->inlineUtf16Length($content)) {
                throw new SuggestionConflictException('Invalid target link range.');
            }
            [$prefix, $rest] = $this->splitInlinesAtUtf16($content, $input->from);
            [$selected, $suffix] = $this->splitInlinesAtUtf16($rest, $input->to - $input->from);
            $changed = false;
            foreach ($selected as $i => $inline) {
                $marks = $inline['marks'] ?? null;
                if (! is_array($marks)) {
                    continue;
                }
                foreach ($marks as $index => $mark) {
                    if (! is_array($mark) || ($mark['type'] ?? null) !== 'link') {
                        continue;
                    }
                    $mAttrs = self::stringMapOrEmpty($mark['attrs'] ?? null);
                    if (($mAttrs[$attr] ?? null) !== $oldValue) {
                        throw new SuggestionConflictException('The target link attribute has changed.');
                    }
                    if ($newValue === null) {
                        unset($mAttrs[$attr]);
                    } else {
                        $mAttrs[$attr] = $newValue;
                    }
                    $mark['attrs'] = $mAttrs;
                    $marks[$index] = $mark;
                    $changed = true;
                }
                $selected[$i]['marks'] = $marks;
            }
            if (! $changed) {
                throw new SuggestionConflictException('The target link mark was not found in the block range.');
            }
            $node['content'] = $this->mergeAdjacentTextRuns([...$prefix, ...$selected, ...$suffix]);
            $applied = true;

            return $node;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target block is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyBlockSplit(array $doc, StructuralSuggestionInput $input): array
    {
        $newBlockId = self::strVal($input->payload['new_block_id'] ?? null);
        $splitKind = self::strVal($input->payload['split_kind'] ?? null, 'structural');
        $newNodeType = self::strVal($input->payload['new_node_type'] ?? null, 'paragraph');
        $newAttrs = self::stringMapOrEmpty($input->payload['new_attrs'] ?? null);
        $position = self::strVal($input->payload['position'] ?? null, 'after');
        $offset = self::intVal($input->payload['offset_utf16'] ?? $input->from, $input->from);

        if (in_array($newBlockId, BlockIdentity::extractIds($doc), true)) {
            throw new SuggestionConflictException('The target new_block_id already exists in the document.');
        }

        $applied = false;
        $updated = $this->walkReplaceContainerChild($doc, function (array $children) use (
            $input,
            $splitKind,
            $newBlockId,
            $newNodeType,
            $newAttrs,
            $position,
            $offset,
            &$applied,
        ): ?array {
            foreach ($children as $idx => $rawChild) {
                if (! is_array($rawChild)) {
                    continue;
                }
                $child = self::stringMapOrEmpty($rawChild);
                $attrs = $child['attrs'] ?? null;
                if (! is_array($attrs) || ($attrs['blockId'] ?? null) !== $input->targetBlockId) {
                    continue;
                }
                $type = $child['type'] ?? null;
                if ($type !== 'paragraph' && $type !== 'heading') {
                    throw UnsupportedSuggestionException::because('Only paragraph or heading blocks support block_split.', 'unsupported_split_target');
                }
                $rawContent = is_array($child['content'] ?? null) && array_is_list($child['content']) ? $child['content'] : [];
                $content = self::nodeList($rawContent);
                $totalLen = $this->inlineUtf16Length($content);
                if ($offset < 0 || $offset > $totalLen) {
                    throw new SuggestionConflictException('Split offset is outside the current block text.');
                }

                $createdAttrs = $newAttrs;
                $createdAttrs['blockId'] = $newBlockId;
                if ($newNodeType === 'heading' && ! isset($createdAttrs['level'])) {
                    $createdAttrs['level'] = is_int($attrs['level'] ?? null) ? $attrs['level'] : 1;
                }

                if ($splitKind === 'insertion') {
                    $newNode = ['type' => $newNodeType, 'attrs' => $createdAttrs];
                    $insertAt = $position === 'before' ? $idx : $idx + 1;
                    array_splice($children, $insertAt, 0, [$newNode]);
                    $applied = true;

                    return $children;
                }

                [$leftInlines, $rightInlines] = $this->splitInlinesAtUtf16($content, $offset);
                $leftNode = $child;
                if ($leftInlines === []) {
                    unset($leftNode['content']);
                } else {
                    $leftNode['content'] = $leftInlines;
                }

                $rightNode = ['type' => $newNodeType, 'attrs' => $createdAttrs];
                if ($rightInlines !== []) {
                    $rightNode['content'] = $rightInlines;
                }

                array_splice($children, $idx, 1, [$leftNode, $rightNode]);
                $applied = true;

                return $children;
            }

            return null;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target block for block_split is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $destroyed
     * @return array<string, mixed>
     */
    private function applyBlockJoin(array $doc, StructuralSuggestionInput $input, array &$destroyed): array
    {
        $secondBlockId = self::strVal($input->payload['second_block_id'] ?? null);
        $expectedOffset = $input->payload['join_offset_utf16'] ?? null;

        $applied = false;
        $updated = $this->walkReplaceContainerChild($doc, function (array $children) use ($input, $secondBlockId, $expectedOffset, &$applied, &$destroyed): ?array {
            $count = count($children);
            for ($i = 0; $i < $count - 1; $i++) {
                $rawFirst = $children[$i] ?? null;
                $rawSecond = $children[$i + 1] ?? null;
                if (! is_array($rawFirst) || ! is_array($rawSecond)) {
                    continue;
                }
                $first = self::stringMapOrEmpty($rawFirst);
                $second = self::stringMapOrEmpty($rawSecond);
                $firstId = is_array($first['attrs'] ?? null) ? ($first['attrs']['blockId'] ?? null) : null;
                $secondId = is_array($second['attrs'] ?? null) ? ($second['attrs']['blockId'] ?? null) : null;
                if ($firstId !== $input->targetBlockId) {
                    continue;
                }
                if ($secondId !== $secondBlockId) {
                    throw new SuggestionConflictException('Adjacent second block does not match block_join precondition.');
                }
                $firstType = $first['type'] ?? null;
                $secondType = $second['type'] ?? null;
                if (! in_array($firstType, ['paragraph', 'heading'], true)
                    || ! in_array($secondType, ['paragraph', 'heading'], true)) {
                    throw UnsupportedSuggestionException::because('Only paragraph or heading blocks can be joined.', 'unsupported_join_target');
                }

                $firstContent = self::nodeList(is_array($first['content'] ?? null) ? $first['content'] : []);
                if (is_int($expectedOffset) && $this->inlineUtf16Length($firstContent) !== $expectedOffset) {
                    throw new SuggestionConflictException('The target block length changed before block_join.');
                }
                $secondContent = self::nodeList(is_array($second['content'] ?? null) ? $second['content'] : []);
                $merged = $this->mergeAdjacentTextRuns([...$firstContent, ...$secondContent]);
                if ($merged === []) {
                    unset($first['content']);
                } else {
                    $first['content'] = $merged;
                }

                array_splice($children, $i, 2, [$first]);
                $destroyed[] = $secondBlockId;
                $applied = true;

                return $children;
            }

            return null;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The adjacent blocks for block_join are unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyListChange(array $doc, StructuralSuggestionInput $input): array
    {
        $listOp = ListOperation::tryFrom(self::strVal($input->payload['list_op'] ?? null))
            ?? throw UnsupportedSuggestionException::because('Unsupported list operation.', 'unsupported_list_operation');
        $listAttrs = self::stringMapOrEmpty($input->payload['list_attrs'] ?? null);

        return match ($listOp) {
            ListOperation::WrapBullet => $this->wrapBlockInList($doc, $input->targetBlockId, 'bulletList', $listAttrs),
            ListOperation::WrapOrdered => $this->wrapBlockInList($doc, $input->targetBlockId, 'orderedList', $listAttrs),
            ListOperation::ToggleType => $this->toggleEnclosingListType($doc, $input->targetBlockId, $listAttrs),
            ListOperation::Lift => $this->liftBlockFromList($doc, $input->targetBlockId),
            ListOperation::SinkItem => $this->sinkListItem($doc, $input->targetBlockId),
            ListOperation::SplitItem => $this->splitListItem($doc, $input),
        };
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $listAttrs
     * @return array<string, mixed>
     */
    private function wrapBlockInList(array $doc, string $targetBlockId, string $listType, array $listAttrs): array
    {
        $applied = false;
        $updated = $this->walkReplaceContainerChild($doc, static function (array $children) use ($targetBlockId, $listType, $listAttrs, &$applied): ?array {
            foreach ($children as $idx => $rawChild) {
                if (! is_array($rawChild)) {
                    continue;
                }
                $child = self::stringMapOrEmpty($rawChild);
                $attrs = is_array($child['attrs'] ?? null) ? $child['attrs'] : [];
                if (($attrs['blockId'] ?? null) !== $targetBlockId) {
                    continue;
                }
                if (($child['type'] ?? null) !== 'paragraph') {
                    throw UnsupportedSuggestionException::because('Only paragraph blocks can be wrapped into a list in v1.', 'node_type_change_unsupported');
                }
                $listNode = ['type' => $listType];
                if ($listType === 'orderedList') {
                    $listNode['attrs'] = $listAttrs === [] ? ['start' => 1] : $listAttrs;
                }
                $listNode['content'] = [[
                    'type' => 'listItem',
                    'content' => [$child],
                ]];
                $children[$idx] = $listNode;
                $applied = true;

                return $children;
            }

            return null;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target paragraph for list wrap is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $listAttrs
     * @return array<string, mixed>
     */
    private function toggleEnclosingListType(array $doc, string $targetBlockId, array $listAttrs): array
    {
        $applied = false;
        $updated = $this->walkToggleInnermostList($doc, $targetBlockId, $listAttrs, $applied);
        if (! $applied) {
            throw new SuggestionConflictException('The enclosing list for toggle_type was not found.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $listAttrs
     * @return array<string, mixed>
     */
    private function walkToggleInnermostList(array $node, string $targetBlockId, array $listAttrs, bool &$applied): array
    {
        $content = $node['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $idx => $rawChild) {
                if (! is_array($rawChild)) {
                    continue;
                }
                $child = self::stringMapOrEmpty($rawChild);
                if ($this->containsBlockId($child, $targetBlockId)) {
                    $content[$idx] = $this->walkToggleInnermostList($child, $targetBlockId, $listAttrs, $applied);
                    if ($applied) {
                        $node['content'] = $content;

                        return $node;
                    }
                }
            }
        }

        $type = $node['type'] ?? null;
        if (($type === 'bulletList' || $type === 'orderedList') && $this->containsBlockId($node, $targetBlockId)) {
            $nextType = $type === 'bulletList' ? 'orderedList' : 'bulletList';
            $node['type'] = $nextType;
            if ($nextType === 'orderedList') {
                $node['attrs'] = $listAttrs === [] ? ['start' => 1] : $listAttrs;
            } else {
                unset($node['attrs']);
            }
            $applied = true;
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function liftBlockFromList(array $doc, string $targetBlockId): array
    {
        $applied = false;
        $updated = $this->walkLiftFromList($doc, $targetBlockId, $applied);
        if (! $applied) {
            throw new SuggestionConflictException('The target list item for lift is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function walkLiftFromList(array $node, string $targetBlockId, bool &$applied): array
    {
        $content = $node['content'] ?? null;
        if (! is_array($content) || ! array_is_list($content)) {
            return $node;
        }

        foreach ($content as $idx => $rawChild) {
            if (! is_array($rawChild)) {
                continue;
            }
            $child = self::stringMapOrEmpty($rawChild);
            if ($this->containsBlockId($child, $targetBlockId)) {
                $content[$idx] = $this->walkLiftFromList($child, $targetBlockId, $applied);
                if ($applied) {
                    $node['content'] = $content;

                    return $node;
                }
            }
        }

        $nextChildren = [];
        $changedHere = false;
        foreach ($content as $rawChild) {
            if (! is_array($rawChild)) {
                $nextChildren[] = $rawChild;

                continue;
            }
            $child = self::stringMapOrEmpty($rawChild);
            $childType = $child['type'] ?? null;
            if (($childType === 'bulletList' || $childType === 'orderedList') && $this->containsBlockId($child, $targetBlockId)) {
                $items = is_array($child['content'] ?? null) ? self::nodeList($child['content']) : [];
                $foundItemIdx = null;
                foreach ($items as $iIdx => $item) {
                    if ($this->directListItemHasBlockId($item, $targetBlockId)) {
                        $foundItemIdx = $iIdx;
                        break;
                    }
                }
                if ($foundItemIdx !== null) {
                    $beforeItems = array_slice($items, 0, $foundItemIdx);
                    $targetItem = $items[$foundItemIdx];
                    $afterItems = array_slice($items, $foundItemIdx + 1);
                    if ($beforeItems !== []) {
                        $nextChildren[] = [...$child, 'content' => $beforeItems];
                    }
                    $extractedBlocks = is_array($targetItem['content'] ?? null) ? self::nodeList($targetItem['content']) : [];
                    foreach ($extractedBlocks as $block) {
                        $nextChildren[] = $block;
                    }
                    if ($afterItems !== []) {
                        $nextChildren[] = [...$child, 'content' => $afterItems];
                    }
                    $changedHere = true;
                    $applied = true;

                    continue;
                }
            }
            $nextChildren[] = $child;
        }

        if ($changedHere) {
            $node['content'] = $nextChildren;
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function sinkListItem(array $doc, string $targetBlockId): array
    {
        $applied = false;
        $updated = $this->walkReplaceNode($doc, function (array $node) use ($targetBlockId, &$applied): ?array {
            $listType = $node['type'] ?? null;
            if ($listType !== 'bulletList' && $listType !== 'orderedList') {
                return null;
            }
            $rawItems = $node['content'] ?? null;
            if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                return null;
            }
            $items = self::nodeList($rawItems);
            foreach ($items as $idx => $item) {
                if (! $this->directListItemHasBlockId($item, $targetBlockId)) {
                    continue;
                }
                if ($idx === 0 || ! isset($items[$idx - 1])) {
                    throw new SuggestionConflictException('First list item cannot be indented.');
                }
                $prevItem = $items[$idx - 1];
                $prevContent = is_array($prevItem['content'] ?? null) ? self::nodeList($prevItem['content']) : [];
                $lastIdx = count($prevContent) - 1;
                if ($lastIdx >= 0 && (($prevContent[$lastIdx]['type'] ?? null) === 'bulletList' || ($prevContent[$lastIdx]['type'] ?? null) === 'orderedList')) {
                    $subList = $prevContent[$lastIdx];
                    $subContent = is_array($subList['content'] ?? null) ? self::nodeList($subList['content']) : [];
                    $subContent[] = $item;
                    $subList['content'] = $subContent;
                    $prevContent[$lastIdx] = $subList;
                } else {
                    $sublist = ['type' => $listType];
                    if ($listType === 'orderedList' && is_array($node['attrs'] ?? null)) {
                        $sublist['attrs'] = $node['attrs'];
                    }
                    $sublist['content'] = [$item];
                    $prevContent[] = $sublist;
                }
                $prevItem['content'] = $prevContent;
                $items[$idx - 1] = $prevItem;
                array_splice($items, $idx, 1);
                $node['content'] = $items;
                $applied = true;

                return $node;
            }

            return null;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target list item for sink_item is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function splitListItem(array $doc, StructuralSuggestionInput $input): array
    {
        $newBlockId = self::strVal($input->payload['new_block_id'] ?? null);
        $offset = self::intVal($input->payload['offset_utf16'] ?? $input->from, $input->from);
        if (in_array($newBlockId, BlockIdentity::extractIds($doc), true)) {
            throw new SuggestionConflictException('The target new_block_id already exists in the document.');
        }

        $applied = false;
        $updated = $this->walkReplaceNode($doc, function (array $node) use ($input, $newBlockId, $offset, &$applied): ?array {
            $type = $node['type'] ?? null;
            if ($type !== 'bulletList' && $type !== 'orderedList') {
                return null;
            }
            $rawItems = $node['content'] ?? null;
            if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                return null;
            }
            $items = self::nodeList($rawItems);
            foreach ($items as $idx => $item) {
                if (! $this->directListItemHasBlockId($item, $input->targetBlockId)) {
                    continue;
                }
                $itemContent = is_array($item['content'] ?? null) ? self::nodeList($item['content']) : [];
                foreach ($itemContent as $bIdx => $block) {
                    $blockAttrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                    if (($blockAttrs['blockId'] ?? null) !== $input->targetBlockId) {
                        continue;
                    }
                    $inlines = is_array($block['content'] ?? null) ? self::nodeList($block['content']) : [];
                    $total = $this->inlineUtf16Length($inlines);
                    if ($offset < 0 || $offset > $total) {
                        throw new SuggestionConflictException('Split offset is outside the list item paragraph.');
                    }
                    [$leftInlines, $rightInlines] = $this->splitInlinesAtUtf16($inlines, $offset);
                    $firstBlock = $block;
                    if ($leftInlines === []) {
                        unset($firstBlock['content']);
                    } else {
                        $firstBlock['content'] = $leftInlines;
                    }
                    $secondBlock = ['type' => 'paragraph', 'attrs' => ['blockId' => $newBlockId]];
                    if ($rightInlines !== []) {
                        $secondBlock['content'] = $rightInlines;
                    }
                    $firstItemBlocks = $itemContent;
                    $firstItemBlocks[$bIdx] = $firstBlock;
                    $trailingSubBlocks = array_slice($firstItemBlocks, $bIdx + 1);
                    $firstItem = [...$item, 'content' => array_slice($firstItemBlocks, 0, $bIdx + 1)];
                    $secondItem = ['type' => 'listItem', 'content' => [$secondBlock, ...$trailingSubBlocks]];
                    array_splice($items, $idx, 1, [$firstItem, $secondItem]);
                    $node['content'] = $items;
                    $applied = true;

                    return $node;
                }
            }

            return null;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target list item for split_item is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $destroyed
     * @return array<string, mixed>
     */
    private function applyTableChange(array $doc, StructuralSuggestionInput $input, array &$destroyed): array
    {
        $tableOp = TableOperation::tryFrom(self::strVal($input->payload['table_op'] ?? null))
            ?? throw UnsupportedSuggestionException::because('Unsupported table operation.', 'unsupported_table_operation');
        if ($tableOp === TableOperation::InsertTable) {
            return $this->applyInsertTable($doc, $input);
        }

        $applied = false;
        $updated = $this->walkReplaceNode($doc, function (array $node) use ($doc, $input, $tableOp, &$applied, &$destroyed): ?array {
            if (($node['type'] ?? null) !== 'table' || ! $this->containsBlockId($node, $input->targetBlockId)) {
                return null;
            }
            $this->assertTableIsTextual($node);
            $node = $this->mutateTableNode($doc, $node, $tableOp, $input, $destroyed);
            $applied = true;

            return $node;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The target textual table is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyInsertTable(array $doc, StructuralSuggestionInput $input): array
    {
        $rows = self::intVal($input->payload['rows'] ?? 2, 2);
        $cols = self::intVal($input->payload['cols'] ?? 2, 2);
        $withHeader = (bool) ($input->payload['with_header_row'] ?? true);
        $providedIds = self::stringList($input->payload['cell_block_ids'] ?? null);
        $providedCells = is_array($input->payload['cells_content'] ?? null) ? array_values($input->payload['cells_content']) : [];
        $existingIds = array_flip(BlockIdentity::extractIds($doc));

        $tableRows = [];
        $cellCursor = 0;
        for ($r = 0; $r < $rows; $r++) {
            $cells = [];
            $cellType = $withHeader && $r === 0 ? 'tableHeader' : 'tableCell';
            for ($c = 0; $c < $cols; $c++) {
                $blockId = $this->nextTableBlockId($providedIds, $cellCursor, $existingIds);
                $cells[] = [
                    'type' => $cellType,
                    'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                    'content' => $this->tableCellContent($providedCells[$cellCursor] ?? '', $blockId, $existingIds),
                ];
                $cellCursor++;
            }
            $tableRows[] = ['type' => 'tableRow', 'content' => $cells];
        }

        $tableNode = ['type' => 'table', 'content' => $tableRows];
        $applied = false;
        $updated = $this->walkReplaceContainerChild($doc, static function (array $children) use ($input, $tableNode, &$applied): ?array {
            foreach ($children as $idx => $rawChild) {
                if (! is_array($rawChild)) {
                    continue;
                }
                $child = self::stringMapOrEmpty($rawChild);
                $attrs = is_array($child['attrs'] ?? null) ? $child['attrs'] : [];
                if (($attrs['blockId'] ?? null) === $input->targetBlockId) {
                    $insertBefore = ($input->payload['insert_before'] ?? false) === true;
                    array_splice($children, $insertBefore ? $idx : $idx + 1, 0, [$tableNode]);
                    $applied = true;

                    return $children;
                }
            }

            return null;
        });

        if (! $applied) {
            throw new SuggestionConflictException('The anchor block for insert_table is unavailable.');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $table
     * @param  list<string>  $destroyed
     * @return array<string, mixed>
     */
    private function mutateTableNode(
        array $doc,
        array $table,
        TableOperation $tableOp,
        StructuralSuggestionInput $input,
        array &$destroyed,
    ): array {
        $rawRows = is_array($table['content'] ?? null) && array_is_list($table['content']) ? $table['content'] : [];
        $rows = self::nodeList($rawRows);
        if ($rows === []) {
            throw new SuggestionConflictException('Table has no rows.');
        }

        $rowCount = count($rows);
        $colCount = $this->tableColCount($rows[0]);
        $existingIds = array_flip(BlockIdentity::extractIds($doc));
        $providedIds = self::stringList($input->payload['cell_block_ids'] ?? null);
        $providedCells = is_array($input->payload['cells_content'] ?? null) ? array_values($input->payload['cells_content']) : [];

        if ($tableOp === TableOperation::InsertRow) {
            $rowIdx = is_int($input->payload['row_index'] ?? null) ? $input->payload['row_index'] : $rowCount;
            if ($rowIdx < 0 || $rowIdx > $rowCount) {
                throw new SuggestionConflictException('Row index is out of bounds.');
            }
            $isHeader = (bool) ($input->payload['is_header'] ?? false);
            $newCells = [];
            for ($c = 0; $c < $colCount; $c++) {
                $bid = $this->nextTableBlockId($providedIds, $c, $existingIds);
                $newCells[] = [
                    'type' => $isHeader ? 'tableHeader' : 'tableCell',
                    'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                    'content' => $this->tableCellContent($providedCells[$c] ?? '', $bid, $existingIds),
                ];
            }
            array_splice($rows, $rowIdx, 0, [['type' => 'tableRow', 'content' => $newCells]]);
            $table['content'] = $rows;

            return $table;
        }

        if ($tableOp === TableOperation::DeleteRow) {
            $rowIdx = $input->payload['row_index'] ?? null;
            if (! is_int($rowIdx) || ! isset($rows[$rowIdx])) {
                throw new SuggestionConflictException('Target table row for deletion does not exist.');
            }
            if ($rowCount <= 1) {
                throw new SuggestionConflictException('Cannot delete the only remaining row of a table.');
            }
            $destroyed = [...$destroyed, ...BlockIdentity::extractIds($rows[$rowIdx])];
            array_splice($rows, $rowIdx, 1);
            $table['content'] = $rows;

            return $table;
        }

        if ($tableOp === TableOperation::InsertColumn) {
            $colIdx = is_int($input->payload['col_index'] ?? null) ? $input->payload['col_index'] : $colCount;
            if ($colIdx < 0 || $colIdx > $colCount) {
                throw new SuggestionConflictException('Column index is out of bounds.');
            }
            $isHeaderCol = (bool) ($input->payload['is_header'] ?? false);
            foreach ($rows as $rIdx => $row) {
                $cells = is_array($row['content'] ?? null) ? self::nodeList($row['content']) : [];
                $firstCell = $cells[0] ?? [];
                $rowIsHeader = $isHeaderCol || ($firstCell['type'] ?? null) === 'tableHeader';
                $bid = $this->nextTableBlockId($providedIds, $rIdx, $existingIds);
                $newCell = [
                    'type' => $rowIsHeader ? 'tableHeader' : 'tableCell',
                    'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                    'content' => $this->tableCellContent($providedCells[$rIdx] ?? '', $bid, $existingIds),
                ];
                array_splice($cells, $colIdx, 0, [$newCell]);
                $rows[$rIdx]['content'] = $cells;
            }
            $table['content'] = $rows;

            return $table;
        }

        if ($tableOp === TableOperation::DeleteColumn) {
            $colIdx = $input->payload['col_index'] ?? null;
            if (! is_int($colIdx) || $colIdx < 0) {
                throw new SuggestionConflictException('Target table column index is invalid.');
            }
            foreach ($rows as $rIdx => $row) {
                $cells = is_array($row['content'] ?? null) ? self::nodeList($row['content']) : [];
                if (count($cells) <= 1 || ! isset($cells[$colIdx])) {
                    throw new SuggestionConflictException('Cannot delete column from table row.');
                }
                $destroyed = [...$destroyed, ...BlockIdentity::extractIds($cells[$colIdx])];
                array_splice($cells, $colIdx, 1);
                $rows[$rIdx]['content'] = $cells;
            }
            $table['content'] = $rows;

            return $table;
        }

        if ($tableOp === TableOperation::ToggleHeaderRow) {
            $rowIdx = is_int($input->payload['row_index'] ?? null) ? $input->payload['row_index'] : 0;
            if (! isset($rows[$rowIdx])) {
                throw new SuggestionConflictException('Target table row does not exist.');
            }
            $cells = is_array($rows[$rowIdx]['content'] ?? null) ? self::nodeList($rows[$rowIdx]['content']) : [];
            $allHeaders = $cells !== [] && array_all($cells, static fn (array $cell): bool => ($cell['type'] ?? null) === 'tableHeader');
            $nextType = $allHeaders ? 'tableCell' : 'tableHeader';
            foreach ($cells as $cIdx => $cell) {
                $cells[$cIdx]['type'] = $nextType;
            }
            $rows[$rowIdx]['content'] = $cells;
            $table['content'] = $rows;

            return $table;
        }

        if ($tableOp === TableOperation::ToggleHeaderCell) {
            [$rowIdx, $colIdx] = $this->locateCellCoords(
                $rows,
                $input->targetBlockId,
                $input->selectorInt('row_index'),
                $input->selectorInt('col_index'),
            );
            $cells = is_array($rows[$rowIdx]['content'] ?? null) ? self::nodeList($rows[$rowIdx]['content']) : [];
            $cell = $cells[$colIdx] ?? null;
            if (! is_array($cell)) {
                throw new SuggestionConflictException('Target table cell does not exist.');
            }
            $cell['type'] = ($cell['type'] ?? null) === 'tableHeader' ? 'tableCell' : 'tableHeader';
            $cells[$colIdx] = $cell;
            $rows[$rowIdx]['content'] = $cells;
            $table['content'] = $rows;

            return $table;
        }

        if ($tableOp !== TableOperation::SetCellAttr) {
            throw UnsupportedSuggestionException::because('Unsupported table operation.', 'unsupported_table_operation');
        }
        [$rowIdx, $colIdx] = $this->locateCellCoords(
            $rows,
            $input->targetBlockId,
            $input->selectorInt('row_index'),
            $input->selectorInt('col_index'),
        );
        $attr = self::strVal($input->payload['attr'] ?? null);
        $oldValue = $input->payload['old_value'] ?? null;
        $newValue = $input->payload['new_value'] ?? null;
        $cells = is_array($rows[$rowIdx]['content'] ?? null) ? self::nodeList($rows[$rowIdx]['content']) : [];
        $cell = $cells[$colIdx] ?? null;
        if (! is_array($cell)) {
            throw new SuggestionConflictException('Target table cell does not exist.');
        }
        $attrs = is_array($cell['attrs'] ?? null) ? $cell['attrs'] : [];
        if (($attrs[$attr] ?? null) !== $oldValue) {
            throw new SuggestionConflictException('The target table cell attribute has changed.');
        }
        if ($newValue === null) {
            unset($attrs[$attr]);
        } else {
            $attrs[$attr] = $newValue;
        }
        $cell['attrs'] = $attrs;
        $cells[$colIdx] = $cell;
        $rows[$rowIdx]['content'] = $cells;
        $table['content'] = $rows;

        return $table;
    }

    /** @param array<string, mixed> $tableNode */
    public function assertTableIsTextual(array $tableNode): void
    {
        $rows = $tableNode['content'] ?? [];
        if (! is_array($rows)) {
            throw UnsupportedSuggestionException::because('Invalid table content.', 'non_textual_table');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['type'] ?? null) !== 'tableRow') {
                throw UnsupportedSuggestionException::because('Unsupported table row structure.', 'non_textual_table');
            }
            $cells = $row['content'] ?? [];
            if (! is_array($cells)) {
                throw UnsupportedSuggestionException::because('Invalid table cells.', 'non_textual_table');
            }
            foreach ($cells as $cell) {
                if (! is_array($cell) || ! in_array($cell['type'] ?? null, ['tableCell', 'tableHeader'], true)) {
                    throw UnsupportedSuggestionException::because('Unsupported cell node in table.', 'non_textual_table');
                }
                $blocks = $cell['content'] ?? [];
                if (! is_array($blocks)) {
                    throw UnsupportedSuggestionException::because('Invalid table cell blocks.', 'non_textual_table');
                }
                foreach ($blocks as $block) {
                    if (! is_array($block) || ! in_array($block['type'] ?? null, ['paragraph', 'heading'], true)) {
                        $blockType = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
                        throw UnsupportedSuggestionException::because(
                            sprintf('Table cell contains non-textual block [%s]; only textual tables are supported.', $blockType),
                            'non_textual_table',
                        );
                    }
                    $inlines = $block['content'] ?? [];
                    if (is_array($inlines)) {
                        foreach ($inlines as $inline) {
                            $inlineType = is_array($inline) ? ($inline['type'] ?? null) : null;
                            if ($inlineType !== 'text' && $inlineType !== 'hardBreak') {
                                $inlineName = is_string($inlineType) ? $inlineType : 'unknown';
                                throw UnsupportedSuggestionException::because(
                                    sprintf('Table cell contains non-textual inline node [%s].', $inlineName),
                                    'non_textual_table',
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $existingIds
     * @return list<array<string, mixed>>
     */
    private function tableCellContent(mixed $cell, string $blockId, array &$existingIds): array
    {
        $candidate = is_array($cell) ? ReviewCapabilities::cellBlocks($cell) : null;
        $blocks = is_array($candidate) ? self::nodeList($candidate) : [];
        if ($blocks === []) {
            $paragraph = ['type' => 'paragraph', 'attrs' => ['blockId' => $blockId]];
            if (is_string($cell) && $cell !== '') {
                $paragraph['content'] = [['type' => 'text', 'text' => $cell]];
            }

            return [$paragraph];
        }

        foreach ($blocks as $index => $block) {
            $attrs = self::stringMapOrEmpty($block['attrs'] ?? null);
            $id = $index === 0 ? $blockId : $this->nextTableBlockId([], 0, $existingIds);
            $blocks[$index]['attrs'] = array_replace($attrs, ['blockId' => $id]);
        }

        return $blocks;
    }

    /** @param array<string, mixed> $doc */
    private function assertDocCandidateTextualIfTableTargeted(array $doc, StructuralSuggestionInput $input): void
    {
        if ($input->kind !== SuggestionKind::TableChange->value) {
            return;
        }
        $found = $this->findTableContainingBlock($doc, $input->targetBlockId);
        if ($found === null) {
            return;
        }
        if (($input->payload['table_op'] ?? null) === TableOperation::InsertTable->value) {
            throw UnsupportedSuggestionException::because('A table cannot be inserted inside another table.', 'non_textual_table');
        }
        $this->assertTableIsTextual($found);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function findTableContainingBlock(array $node, string $blockId): ?array
    {
        if (($node['type'] ?? null) === 'table' && $this->containsBlockId($node, $blockId)) {
            return $node;
        }
        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $rawChild) {
                if (is_array($rawChild)) {
                    $hit = $this->findTableContainingBlock(self::stringMapOrEmpty($rawChild), $blockId);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $doc */
    private function assertValidRichText(array $doc): void
    {
        try {
            BlockIdentity::assertComplete($doc);
            TipTapContent::fromArray($doc, TipTapSchema::richText());
        } catch (InvalidDocumentContentException $e) {
            throw UnsupportedSuggestionException::because($e->getMessage(), 'schema_validation_failed');
        }
    }

    /**
     * @param  list<string>  $providedIds
     * @param  array<string, int>  $existingIds
     */
    private function nextTableBlockId(array $providedIds, int $index, array &$existingIds): string
    {
        $candidate = $providedIds[$index] ?? null;
        if (is_string($candidate) && BlockIdentity::isValid($candidate)) {
            if (isset($existingIds[$candidate])) {
                throw new SuggestionConflictException('Duplicate table cell blockId.');
            }
            $existingIds[$candidate] = 1;

            return $candidate;
        }
        do {
            $generated = BlockIdentity::generate();
        } while (isset($existingIds[$generated]));
        $existingIds[$generated] = 1;

        return $generated;
    }

    /** @param array<string, mixed> $row */
    private function tableColCount(array $row): int
    {
        $cells = is_array($row['content'] ?? null) ? $row['content'] : [];

        return max(1, count($cells));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: int, 1: int}
     */
    private function locateCellCoords(array $rows, string $targetBlockId, ?int $rowIdx, ?int $colIdx): array
    {
        if ($rowIdx !== null && $colIdx !== null) {
            $row = $rows[$rowIdx] ?? null;
            $cells = is_array($row['content'] ?? null) ? $row['content'] : [];
            if ($row === null || ! isset($cells[$colIdx])) {
                throw new SuggestionConflictException('Target table cell coordinates are out of range.');
            }

            return [$rowIdx, $colIdx];
        }
        foreach ($rows as $rIdx => $row) {
            $cells = is_array($row['content'] ?? null) ? self::nodeList($row['content']) : [];
            foreach ($cells as $cIdx => $cell) {
                if ($this->containsBlockId($cell, $targetBlockId)) {
                    return [$rIdx, $cIdx];
                }
            }
        }

        throw new SuggestionConflictException('Target table cell could not be located.');
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $replacer
     * @return array<string, mixed>
     */
    private function walkReplaceNode(array $node, callable $replacer): array
    {
        $replaced = $replacer($node);
        if ($replaced !== null) {
            return $replaced;
        }
        $content = $node['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $idx => $rawChild) {
                if (is_array($rawChild)) {
                    $child = self::stringMapOrEmpty($rawChild);
                    $nextChild = $this->walkReplaceNode($child, $replacer);
                    if ($nextChild !== $child) {
                        $content[$idx] = $nextChild;
                        $node['content'] = $content;

                        return $node;
                    }
                }
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(list<mixed>): ?list<mixed>  $replacer
     * @return array<string, mixed>
     */
    private function walkReplaceContainerChild(array $node, callable $replacer): array
    {
        $content = $node['content'] ?? null;
        if (is_array($content) && array_is_list($content)) {
            $next = $replacer($content);
            if ($next !== null) {
                $node['content'] = $next;

                return $node;
            }
            foreach ($content as $idx => $rawChild) {
                if (is_array($rawChild)) {
                    $child = self::stringMapOrEmpty($rawChild);
                    $nextChild = $this->walkReplaceContainerChild($child, $replacer);
                    if ($nextChild !== $child) {
                        $content[$idx] = $nextChild;
                        $node['content'] = $content;

                        return $node;
                    }
                }
            }
        }

        return $node;
    }

    /** @param array<string, mixed> $node */
    private function containsBlockId(array $node, string $blockId): bool
    {
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && ($attrs['blockId'] ?? null) === $blockId) {
            return true;
        }
        $content = $node['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $rawChild) {
                if (is_array($rawChild) && $this->containsBlockId(self::stringMapOrEmpty($rawChild), $blockId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $item */
    private function directListItemHasBlockId(array $item, string $blockId): bool
    {
        if (($item['type'] ?? null) !== 'listItem' || ! is_array($item['content'] ?? null)) {
            return false;
        }
        foreach ($item['content'] as $child) {
            if (is_array($child) && is_array($child['attrs'] ?? null) && ($child['attrs']['blockId'] ?? null) === $blockId) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $content */
    private function inlineUtf16Length(array $content): int
    {
        $total = 0;
        foreach ($content as $inline) {
            $text = ($inline['type'] ?? null) === 'text' && is_string($inline['text'] ?? null) ? $inline['text'] : null;
            $total += $text === null ? 1 : SnapshotAnchorResolver::utf16Length($text);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function splitInlinesAtUtf16(array $content, int $offset): array
    {
        $left = [];
        $right = [];
        $pos = 0;
        foreach ($content as $inline) {
            $text = ($inline['type'] ?? null) === 'text' && is_string($inline['text'] ?? null) ? $inline['text'] : null;
            $len = $text === null ? 1 : SnapshotAnchorResolver::utf16Length($text);
            $end = $pos + $len;
            if ($end <= $offset) {
                $left[] = $inline;
            } elseif ($pos >= $offset) {
                $right[] = $inline;
            } else {
                if ($text === null) {
                    throw new SuggestionConflictException('Cannot split inside an atomic inline node.');
                }
                $rel = $offset - $pos;
                $prefix = SnapshotTextEdit::slice($text, 0, $rel);
                $suffix = SnapshotTextEdit::slice($text, $rel, $len);
                if ($prefix !== '') {
                    $left[] = [...$inline, 'text' => $prefix];
                }
                if ($suffix !== '') {
                    $right[] = [...$inline, 'text' => $suffix];
                }
            }
            $pos = $end;
        }

        return [$left, $right];
    }

    /**
     * @param  list<mixed>  $marks
     * @param  array<string, mixed>  $newMark
     * @return list<mixed>
     */
    private function upsertMark(array $marks, string $markName, array $newMark): array
    {
        $filtered = [];
        foreach ($marks as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === $markName) {
                continue;
            }
            $filtered[] = $mark;
        }
        $filtered[] = $newMark;

        return $filtered;
    }

    /**
     * @param  list<mixed>  $marks
     * @return array{0: list<mixed>, 1: bool}
     */
    private function removeMarkByType(array $marks, string $markName): array
    {
        $removed = false;
        $filtered = [];
        foreach ($marks as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === $markName) {
                $removed = true;

                continue;
            }
            $filtered[] = $mark;
        }

        return [$filtered, $removed];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function mergeAdjacentTextRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            $lastIdx = array_key_last($merged);
            if ($lastIdx !== null
                && ($merged[$lastIdx]['type'] ?? null) === 'text'
                && ($run['type'] ?? null) === 'text'
                && ($merged[$lastIdx]['marks'] ?? []) === ($run['marks'] ?? [])) {
                $prevText = is_string($merged[$lastIdx]['text'] ?? null) ? $merged[$lastIdx]['text'] : '';
                $curText = is_string($run['text'] ?? null) ? $run['text'] : '';
                $merged[$lastIdx]['text'] = $prevText.$curText;

                continue;
            }
            $merged[] = $run;
        }

        return $merged;
    }

    private static function strVal(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function intVal(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /** @return array<string, mixed> */
    private static function stringMapOrEmpty(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private static function nodeList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = self::stringMapOrEmpty($item);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
