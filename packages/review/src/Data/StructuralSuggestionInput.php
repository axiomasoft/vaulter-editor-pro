<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;

/**
 * Validated structural review suggestion draft covering v1 structural profiles:
 * `mark_change`, `attr_change`, `block_split`, `block_join`, `list_change`, `table_change`.
 */
#[Api(since: '1.0')]
final readonly class StructuralSuggestionInput
{
    public int $from;

    public int $to;

    public string $before;

    public string $after;

    /** @var array<string, mixed> */
    public array $selector;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * @param  array<string, mixed>  $selector
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $groupId,
        public int $generation,
        public int $basePosition,
        public int $schemaVersion,
        public string $kind,
        public string $targetBlockId,
        array $selector = [],
        array $payload = [],
        public ?string $proposalId = null,
        public ?string $parentId = null,
    ) {
        foreach ([$id, $groupId, $proposalId, $parentId] as $uuid) {
            if ($uuid !== null && (! Str::isUuid($uuid) || $uuid !== strtolower($uuid))) {
                throw new InvalidArgumentException('Suggestion identifiers must be lowercase UUIDs.');
            }
        }
        $parsedKind = SuggestionKind::tryFrom($kind);
        if ($parsedKind === null || ! $parsedKind->isStructural()) {
            throw UnsupportedSuggestionException::because(
                sprintf('Suggestion kind [%s] is not a supported structural review profile.', $kind),
                'unsupported_suggestion_kind',
            );
        }
        if ($generation < 0 || $generation > DocumentMutationState::MAX_WIRE_COUNTER
            || $basePosition < 0 || $basePosition > DocumentMutationState::MAX_WIRE_COUNTER
            || $schemaVersion < 1
            || strlen($targetBlockId) < 1 || strlen($targetBlockId) > 255
            || ! mb_check_encoding($targetBlockId, 'UTF-8')
            || str_contains($targetBlockId, "\0")
            || $parentId !== null) {
            throw new InvalidArgumentException('Invalid structural suggestion metadata.');
        }

        $rawFrom = $selector['from_utf16'] ?? 0;
        $rawTo = $selector['to_utf16'] ?? $rawFrom;
        if (! is_int($rawFrom) || ! is_int($rawTo) || $rawFrom < 0 || $rawTo < $rawFrom || $rawTo > DocumentMutationState::MAX_WIRE_COUNTER) {
            throw new InvalidArgumentException('Invalid UTF-16 selector bounds.');
        }

        $beforeRaw = $payload['before'] ?? '';
        $afterRaw = $payload['after'] ?? '';
        $before = is_string($beforeRaw) ? $beforeRaw : json_encode($beforeRaw, JSON_THROW_ON_ERROR);
        $after = is_string($afterRaw) ? $afterRaw : json_encode($afterRaw, JSON_THROW_ON_ERROR);
        if (! mb_check_encoding([$before, $after], 'UTF-8') || str_contains($before.$after, "\0")) {
            throw new InvalidArgumentException('Invalid UTF-8 suggestion payload summary.');
        }

        $this->from = $rawFrom;
        $this->to = $rawTo;
        $this->before = $before;
        $this->after = $after;
        $this->selector = $selector;
        $this->payload = $payload + ['before' => $before, 'after' => $after, 'profile' => $kind];
    }

    public function selectorInt(string $key): ?int
    {
        $val = $this->selector[$key] ?? null;

        return is_int($val) ? $val : null;
    }

    /**
     * @param  array<string, mixed>  $markAttrs
     */
    public static function markChange(
        string $id,
        string $groupId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $targetBlockId,
        int $from,
        int $to,
        string $markName,
        string $operation = 'added',
        array $markAttrs = [],
        string $quoteText = '',
        ?string $proposalId = null,
    ): self {
        if ($from >= $to) {
            throw new InvalidArgumentException('A mark_change requires a non-empty UTF-16 range.');
        }

        return new self(
            id: $id,
            groupId: $groupId,
            generation: $generation,
            basePosition: $basePosition,
            schemaVersion: $schemaVersion,
            kind: SuggestionKind::MarkChange->value,
            targetBlockId: $targetBlockId,
            selector: [
                'from_utf16' => $from,
                'to_utf16' => $to,
                'scope' => 'inline',
            ],
            payload: [
                'mark_name' => $markName,
                'mark_attrs' => $markAttrs,
                'operation' => $operation,
                'quote_text' => $quoteText,
                'before' => $operation === 'added' ? $quoteText : $markName.':'.$quoteText,
                'after' => $operation === 'added' ? $markName.':'.$quoteText : $quoteText,
            ],
            proposalId: $proposalId,
        );
    }

    public static function attrChange(
        string $id,
        string $groupId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $targetBlockId,
        string $nodeType,
        string $attr,
        mixed $oldValue,
        mixed $newValue,
        ?int $rowIndex = null,
        ?int $colIndex = null,
        ?string $proposalId = null,
    ): self {
        $selector = [
            'from_utf16' => 0,
            'to_utf16' => 0,
            'scope' => 'block_attr',
            'node_type' => $nodeType,
            'attr' => $attr,
        ];
        if ($rowIndex !== null) {
            $selector['row_index'] = $rowIndex;
        }
        if ($colIndex !== null) {
            $selector['col_index'] = $colIndex;
        }

        return new self(
            id: $id,
            groupId: $groupId,
            generation: $generation,
            basePosition: $basePosition,
            schemaVersion: $schemaVersion,
            kind: SuggestionKind::AttrChange->value,
            targetBlockId: $targetBlockId,
            selector: $selector,
            payload: [
                'node_type' => $nodeType,
                'attr' => $attr,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'before' => json_encode($oldValue, JSON_THROW_ON_ERROR),
                'after' => json_encode($newValue, JSON_THROW_ON_ERROR),
            ],
            proposalId: $proposalId,
        );
    }

    /**
     * @param  array<string, mixed>  $newAttrs
     */
    public static function blockSplit(
        string $id,
        string $groupId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $targetBlockId,
        int $offset,
        string $splitKind,
        string $newBlockId,
        string $newNodeType = 'paragraph',
        array $newAttrs = [],
        string $position = 'after',
        ?string $proposalId = null,
    ): self {
        return new self(
            id: $id,
            groupId: $groupId,
            generation: $generation,
            basePosition: $basePosition,
            schemaVersion: $schemaVersion,
            kind: SuggestionKind::BlockSplit->value,
            targetBlockId: $targetBlockId,
            selector: [
                'from_utf16' => $offset,
                'to_utf16' => $offset,
                'scope' => 'block_split',
                'split_kind' => $splitKind,
                'new_block_id' => $newBlockId,
            ],
            payload: [
                'offset_utf16' => $offset,
                'split_kind' => $splitKind,
                'new_block_id' => $newBlockId,
                'new_node_type' => $newNodeType,
                'new_attrs' => $newAttrs,
                'position' => $position,
                'before' => $targetBlockId,
                'after' => $targetBlockId.'+'.$newBlockId,
            ],
            proposalId: $proposalId,
        );
    }

    /**
     * @param  array<string, mixed>  $secondAttrs
     */
    public static function blockJoin(
        string $id,
        string $groupId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $targetBlockId,
        string $secondBlockId,
        ?int $joinOffsetUtf16 = null,
        string $secondNodeType = 'paragraph',
        array $secondAttrs = [],
        ?string $proposalId = null,
    ): self {
        $offset = $joinOffsetUtf16 ?? 0;

        return new self(
            id: $id,
            groupId: $groupId,
            generation: $generation,
            basePosition: $basePosition,
            schemaVersion: $schemaVersion,
            kind: SuggestionKind::BlockJoin->value,
            targetBlockId: $targetBlockId,
            selector: [
                'from_utf16' => $offset,
                'to_utf16' => $offset,
                'scope' => 'block_join',
                'second_block_id' => $secondBlockId,
            ],
            payload: [
                'second_block_id' => $secondBlockId,
                'join_offset_utf16' => $joinOffsetUtf16,
                'second_node_type' => $secondNodeType,
                'second_attrs' => $secondAttrs,
                'before' => $targetBlockId.'+'.$secondBlockId,
                'after' => $targetBlockId,
            ],
            proposalId: $proposalId,
        );
    }

    /**
     * @param  array<string, mixed>  $listAttrs
     */
    public static function listChange(
        string $id,
        string $groupId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $targetBlockId,
        string $listOp,
        array $listAttrs = [],
        ?string $newBlockId = null,
        int $offset = 0,
        ?string $proposalId = null,
    ): self {
        return new self(
            id: $id,
            groupId: $groupId,
            generation: $generation,
            basePosition: $basePosition,
            schemaVersion: $schemaVersion,
            kind: SuggestionKind::ListChange->value,
            targetBlockId: $targetBlockId,
            selector: [
                'from_utf16' => $offset,
                'to_utf16' => $offset,
                'scope' => 'list',
                'list_op' => $listOp,
                'new_block_id' => $newBlockId,
            ],
            payload: [
                'list_op' => $listOp,
                'list_attrs' => $listAttrs,
                'new_block_id' => $newBlockId,
                'offset_utf16' => $offset,
                'before' => 'list:'.$targetBlockId,
                'after' => $listOp.':'.$targetBlockId,
            ],
            proposalId: $proposalId,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function tableChange(
        string $id,
        string $groupId,
        int $generation,
        int $basePosition,
        int $schemaVersion,
        string $targetBlockId,
        string $tableOp,
        array $options = [],
        ?string $proposalId = null,
    ): self {
        $selector = [
            'from_utf16' => 0,
            'to_utf16' => 0,
            'scope' => 'table',
            'table_op' => $tableOp,
        ];
        if (is_int($options['row_index'] ?? null)) {
            $selector['row_index'] = $options['row_index'];
        }
        if (is_int($options['col_index'] ?? null)) {
            $selector['col_index'] = $options['col_index'];
        }

        return new self(
            id: $id,
            groupId: $groupId,
            generation: $generation,
            basePosition: $basePosition,
            schemaVersion: $schemaVersion,
            kind: SuggestionKind::TableChange->value,
            targetBlockId: $targetBlockId,
            selector: $selector,
            payload: [
                'table_op' => $tableOp,
                ...$options,
                'before' => $options['before'] ?? ('table:'.$targetBlockId),
                'after' => $options['after'] ?? ($tableOp.':'.$targetBlockId),
            ],
            proposalId: $proposalId,
        );
    }
}
