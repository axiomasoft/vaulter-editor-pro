<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Suggestions;

use Packman\Attributes\Internal;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\LinkSchemeValidator;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\EditorPro\Review\Data\ReviewCapabilitiesData;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Enums\ListOperation;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;
use Vaulter\EditorPro\Review\Enums\TableOperation;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;

/**
 * Resolves supported review capabilities and validates structural suggestions fail-closed prior to write.
 */
#[Internal]
final readonly class ReviewCapabilities
{
    public const string TABLE_MODE = 'text_only';

    public const string OVERLAP_POLICY = 'keep_open_with_conflict';

    /** @var list<string> */
    public const array LIST_OPERATIONS = [
        ListOperation::WrapBullet->value,
        ListOperation::WrapOrdered->value,
        ListOperation::Lift->value,
        ListOperation::ToggleType->value,
        ListOperation::SinkItem->value,
        ListOperation::SplitItem->value,
    ];

    /** @var list<string> */
    public const array TABLE_OPERATIONS = [
        TableOperation::InsertTable->value,
        TableOperation::InsertRow->value,
        TableOperation::DeleteRow->value,
        TableOperation::InsertColumn->value,
        TableOperation::DeleteColumn->value,
        TableOperation::ToggleHeaderRow->value,
        TableOperation::ToggleHeaderCell->value,
        TableOperation::SetCellAttr->value,
    ];

    /** @var list<string> */
    private const array ATTRIBUTE_TARGET_NODES = [
        'heading',
        'paragraph',
        'orderedList',
        'tableCell',
        'tableHeader',
        'link',
    ];

    private const int MAX_NEW_TABLE_CELLS = 1000;

    public function build(
        ?string $documentType = null,
        ?string $writeMode = null,
        bool $journalSupported = false,
        ?int $schemaVersion = null,
    ): ReviewCapabilitiesData {
        $schema = TipTapSchema::richText();
        $allowedAttributes = [];
        foreach (self::ATTRIBUTE_TARGET_NODES as $nodeType) {
            $attrs = array_values(array_filter(
                $schema->attributes($nodeType),
                static fn (string $attr): bool => $attr !== BlockIdentity::ATTR_KEY,
            ));
            if ($attrs !== []) {
                $allowedAttributes[$nodeType] = $attrs;
            }
        }

        $supported = ($documentType === null || ($schemaVersion !== null && $this->supportsDocument($documentType, $schemaVersion)))
            && ($writeMode !== 'journal' || $journalSupported);

        return new ReviewCapabilitiesData(
            supportedKinds: $supported ? SuggestionKind::allValues() : [],
            textKinds: $supported ? SuggestionKind::textValues() : [],
            structuralKinds: $supported ? SuggestionKind::structuralValues() : [],
            nodeTypeChangeTypes: [],
            tableMode: self::TABLE_MODE,
            allowedMarks: $supported ? $schema->markTypes() : [],
            allowedAttributes: $supported ? $allowedAttributes : [],
            listOperations: $supported ? self::LIST_OPERATIONS : [],
            tableOperations: $supported ? self::TABLE_OPERATIONS : [],
            overlapPolicy: self::OVERLAP_POLICY,
            documentType: $documentType,
            writeMode: $writeMode,
            journalSupported: $journalSupported,
        );
    }

    public function assertDocumentSupported(string $documentType, int $schemaVersion): void
    {
        if (! $this->supportsDocument($documentType, $schemaVersion)) {
            throw UnsupportedSuggestionException::because(
                sprintf('Document type [%s] at schema v%d does not support v1 structural review.', $documentType, $schemaVersion),
                'unsupported_document_type',
            );
        }
    }

    private function supportsDocument(string $documentType, int $schemaVersion): bool
    {
        return $documentType === RichTextDocumentType::KEY && $schemaVersion >= 2;
    }

    public function assertInputSupported(TextSuggestionInput|StructuralSuggestionInput $input): void
    {
        if ($input instanceof TextSuggestionInput) {
            if (! BlockIdentity::isValid($input->targetBlockId)) {
                throw UnsupportedSuggestionException::because('Invalid target blockId.', 'invalid_target_block');
            }

            return;
        }

        $kind = SuggestionKind::tryFrom($input->kind);
        if ($kind === null || ! $kind->isStructural()) {
            throw UnsupportedSuggestionException::because(
                sprintf('Unsupported suggestion kind [%s].', $input->kind),
                'unsupported_suggestion_kind',
            );
        }

        $schema = TipTapSchema::richText();

        match ($kind) {
            SuggestionKind::MarkChange => $this->validateMarkChange($input, $schema),
            SuggestionKind::AttrChange => $this->validateAttrChange($input, $schema),
            SuggestionKind::BlockSplit => $this->validateBlockSplit($input, $schema),
            SuggestionKind::BlockJoin => $this->validateBlockJoin($input),
            SuggestionKind::ListChange => $this->validateListChange($input, $schema),
            SuggestionKind::TableChange => $this->validateTableChange($input, $schema),
            default => throw UnsupportedSuggestionException::because(
                sprintf('Unsupported suggestion kind [%s].', $input->kind),
                'unsupported_suggestion_kind',
            ),
        };
    }

    private function validateMarkChange(StructuralSuggestionInput $input, TipTapSchema $schema): void
    {
        $markName = $input->payload['mark_name'] ?? null;
        $operation = $input->payload['operation'] ?? null;
        $markAttrs = $input->payload['mark_attrs'] ?? [];
        if (! is_string($markName) || $markName === '' || ! $schema->allowsMark($markName)) {
            throw UnsupportedSuggestionException::because(
                sprintf('Mark [%s] is not allowed by review capabilities.', is_string($markName) ? $markName : 'unknown'),
                'unsupported_mark',
            );
        }
        if ($operation !== 'added' && $operation !== 'removed') {
            throw UnsupportedSuggestionException::because('Mark change operation must be added or removed.', 'invalid_mark_operation');
        }
        if (! is_array($markAttrs)) {
            throw UnsupportedSuggestionException::because('Mark attributes must be an object.', 'invalid_mark_attrs');
        }
        $allowedAttrs = $schema->attributes($markName);
        $urlAttrs = $schema->urlAttributes($markName);
        foreach ($markAttrs as $key => $val) {
            if (! is_string($key) || ! in_array($key, $allowedAttrs, true)) {
                throw UnsupportedSuggestionException::because(
                    sprintf('Mark attribute [%s.%s] is not supported.', $markName, (string) $key),
                    'unsupported_mark_attribute',
                );
            }
            if ($val !== null) {
                $this->assertSafeAttrValue($markName, $key, $val, $urlAttrs);
            }
        }
        if ($markName === 'link') {
            $href = $markAttrs['href'] ?? null;
            if (! is_string($href) || LinkSchemeValidator::sanitize($href) === null) {
                throw UnsupportedSuggestionException::because('Link mark requires a safe http/https/mailto href.', 'unsafe_link_href');
            }
        }
    }

    private function validateAttrChange(StructuralSuggestionInput $input, TipTapSchema $schema): void
    {
        $nodeType = $input->payload['node_type'] ?? null;
        $attr = $input->payload['attr'] ?? null;
        $oldValue = $input->payload['old_value'] ?? null;
        $newValue = $input->payload['new_value'] ?? null;

        if ($attr === 'type' || $attr === 'nodeType' || $nodeType === 'nodeTypeChange') {
            throw UnsupportedSuggestionException::because(
                'Changing node types via attr_change is disabled (nodeTypeChangeTypes is empty in v1).',
                'node_type_change_unsupported',
            );
        }
        if ($attr === BlockIdentity::ATTR_KEY) {
            throw UnsupportedSuggestionException::because('Block identity (blockId) cannot be altered via attr_change.', 'immutable_block_id');
        }
        if (! is_string($nodeType) || ! in_array($nodeType, self::ATTRIBUTE_TARGET_NODES, true)) {
            throw UnsupportedSuggestionException::because(
                sprintf('Node type [%s] does not support attr_change in v1.', is_string($nodeType) ? $nodeType : 'unknown'),
                'unsupported_attr_target',
            );
        }
        if (! is_string($attr) || ! in_array($attr, $schema->attributes($nodeType), true)) {
            throw UnsupportedSuggestionException::because(
                sprintf('Attribute [%s.%s] is not allowed.', $nodeType, is_string($attr) ? $attr : 'unknown'),
                'unsupported_attribute',
            );
        }
        if ($oldValue === $newValue) {
            throw UnsupportedSuggestionException::because('Attribute change must modify the value.', 'noop_attr_change');
        }
        if ($nodeType === 'heading' && $attr === 'level') {
            if (! is_int($newValue) || $newValue < 1 || $newValue > 6) {
                throw UnsupportedSuggestionException::because('Heading level must be an integer between 1 and 6.', 'invalid_heading_level');
            }
        }
        if (($nodeType === 'tableCell' || $nodeType === 'tableHeader') && ($attr === 'colspan' || $attr === 'rowspan')) {
            if (! is_int($newValue) || $newValue < 1 || $newValue > 100) {
                throw UnsupportedSuggestionException::because('Cell span must be a positive integer.', 'invalid_table_span');
            }
        }
        if ($newValue !== null) {
            $this->assertSafeAttrValue($nodeType, $attr, $newValue, $schema->urlAttributes($nodeType));
        }
    }

    private function validateBlockSplit(StructuralSuggestionInput $input, TipTapSchema $schema): void
    {
        $splitKind = $input->payload['split_kind'] ?? null;
        $newBlockId = $input->payload['new_block_id'] ?? null;
        $newNodeType = $input->payload['new_node_type'] ?? 'paragraph';
        $position = $input->payload['position'] ?? 'after';
        $newAttrs = $input->payload['new_attrs'] ?? [];

        if ($splitKind !== 'structural' && $splitKind !== 'insertion') {
            throw UnsupportedSuggestionException::because('block_split requires split_kind = structural or insertion.', 'invalid_split_kind');
        }
        if (! is_string($newBlockId) || ! BlockIdentity::isValid($newBlockId) || $newBlockId === $input->targetBlockId) {
            throw UnsupportedSuggestionException::because('block_split requires a distinct valid new_block_id.', 'invalid_new_block_id');
        }
        if ($newNodeType !== 'paragraph' && $newNodeType !== 'heading') {
            throw UnsupportedSuggestionException::because(
                sprintf('Splitting into node type [%s] is unsupported (nodeTypeChangeTypes is empty in v1).', is_string($newNodeType) ? $newNodeType : 'unknown'),
                'node_type_change_unsupported',
            );
        }
        if ($position !== 'after' && $position !== 'before') {
            throw UnsupportedSuggestionException::because('Invalid block_split insertion position.', 'invalid_split_position');
        }
        if ($position === 'before' && $splitKind !== 'insertion') {
            throw UnsupportedSuggestionException::because('Splitting before an anchor is only valid for insertion splits.', 'invalid_split_position');
        }
        if (! is_array($newAttrs)) {
            throw UnsupportedSuggestionException::because('new_attrs must be an object.', 'invalid_split_attrs');
        }
        $allowed = $schema->attributes($newNodeType);
        foreach ($newAttrs as $k => $v) {
            if (! is_string($k) || ! in_array($k, $allowed, true)) {
                throw UnsupportedSuggestionException::because(
                    sprintf('Attribute [%s.%s] is not allowed.', $newNodeType, (string) $k),
                    'unsupported_attribute',
                );
            }
            if ($v !== null) {
                $this->assertSafeAttrValue($newNodeType, $k, $v, $schema->urlAttributes($newNodeType));
            }
        }
    }

    private function validateBlockJoin(StructuralSuggestionInput $input): void
    {
        $secondBlockId = $input->payload['second_block_id'] ?? null;
        $secondNodeType = $input->payload['second_node_type'] ?? 'paragraph';
        if (! is_string($secondBlockId) || ! BlockIdentity::isValid($secondBlockId) || $secondBlockId === $input->targetBlockId) {
            throw UnsupportedSuggestionException::because('block_join requires a distinct valid second_block_id.', 'invalid_second_block_id');
        }
        if ($secondNodeType !== 'paragraph' && $secondNodeType !== 'heading') {
            throw UnsupportedSuggestionException::because(
                sprintf('Joining non-textblock node type [%s] is unsupported.', is_string($secondNodeType) ? $secondNodeType : 'unknown'),
                'node_type_change_unsupported',
            );
        }
    }

    private function validateListChange(StructuralSuggestionInput $input, TipTapSchema $schema): void
    {
        $rawListOp = $input->payload['list_op'] ?? null;
        $listOp = is_string($rawListOp) ? ListOperation::tryFrom($rawListOp) : null;
        if ($listOp === null) {
            throw UnsupportedSuggestionException::because(
                sprintf('List operation [%s] is not supported.', is_string($rawListOp) ? $rawListOp : 'unknown'),
                'unsupported_list_operation',
            );
        }
        if ($listOp === ListOperation::SplitItem) {
            $newBlockId = $input->payload['new_block_id'] ?? null;
            if (! is_string($newBlockId) || ! BlockIdentity::isValid($newBlockId) || $newBlockId === $input->targetBlockId) {
                throw UnsupportedSuggestionException::because('list_change split_item requires a distinct valid new_block_id.', 'invalid_new_block_id');
            }
        }
        $listAttrs = $input->payload['list_attrs'] ?? [];
        if (! is_array($listAttrs)) {
            throw UnsupportedSuggestionException::because('list_attrs must be an object.', 'invalid_list_attrs');
        }
        $allowed = $schema->attributes('orderedList');
        foreach ($listAttrs as $key => $val) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw UnsupportedSuggestionException::because(
                    sprintf('List attribute [%s] is not allowed.', (string) $key),
                    'unsupported_list_attribute',
                );
            }
            if ($val !== null) {
                $this->assertSafeAttrValue('orderedList', $key, $val, $schema->urlAttributes('orderedList'));
            }
        }
    }

    private function validateTableChange(StructuralSuggestionInput $input, TipTapSchema $schema): void
    {
        $rawTableOp = $input->payload['table_op'] ?? null;
        $tableOp = is_string($rawTableOp) ? TableOperation::tryFrom($rawTableOp) : null;
        if ($tableOp === null) {
            throw UnsupportedSuggestionException::because(
                sprintf('Table operation [%s] is not supported.', is_string($rawTableOp) ? $rawTableOp : 'unknown'),
                'unsupported_table_operation',
            );
        }

        if ($tableOp === TableOperation::InsertTable) {
            $rows = $input->payload['rows'] ?? 2;
            $cols = $input->payload['cols'] ?? 2;
            if (! is_int($rows) || ! is_int($cols) || $rows < 1 || $cols < 1
                || $rows > intdiv(self::MAX_NEW_TABLE_CELLS, $cols)) {
                throw UnsupportedSuggestionException::because('A new table must have between 1 and 1000 cells.', 'invalid_table_dimensions');
            }
        }

        if (isset($input->payload['cells_content'])) {
            $this->assertCandidateCellsTextual($input->payload['cells_content'], $schema);
        }
        if (isset($input->payload['cell_block_ids'])) {
            if (! is_array($input->payload['cell_block_ids']) || ! array_is_list($input->payload['cell_block_ids'])) {
                throw UnsupportedSuggestionException::because('cell_block_ids must be a list.', 'invalid_cell_block_ids');
            }
            foreach ($input->payload['cell_block_ids'] as $cid) {
                if (! is_string($cid) || ! BlockIdentity::isValid($cid)) {
                    throw UnsupportedSuggestionException::because('Table cell blockId must be valid.', 'invalid_cell_block_id');
                }
            }
        }
        if ($tableOp === TableOperation::SetCellAttr) {
            $attr = $input->payload['attr'] ?? null;
            if (! is_string($attr) || ! in_array($attr, $schema->attributes('tableCell'), true)) {
                throw UnsupportedSuggestionException::because('Unsupported table cell attribute.', 'unsupported_table_cell_attr');
            }
            $newValue = $input->payload['new_value'] ?? null;
            if ($newValue !== null) {
                $this->assertSafeAttrValue('tableCell', $attr, $newValue, $schema->urlAttributes('tableCell'));
            }
        }
    }

    private function assertCandidateCellsTextual(mixed $cellsContent, TipTapSchema $schema): void
    {
        if (! is_array($cellsContent) || ! array_is_list($cellsContent)) {
            throw UnsupportedSuggestionException::because('Table cells_content must be an array.', 'non_textual_table');
        }
        foreach ($cellsContent as $cell) {
            if (is_string($cell)) {
                continue;
            }
            if (! is_array($cell)) {
                throw UnsupportedSuggestionException::because('Non-textual table cell content is rejected in v1.', 'non_textual_table');
            }
            $blocks = self::cellBlocks($cell);
            if (! is_array($blocks)) {
                throw UnsupportedSuggestionException::because('Non-textual table cell block is rejected in v1.', 'non_textual_table');
            }
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    throw UnsupportedSuggestionException::because('Non-textual table cell block is rejected in v1.', 'non_textual_table');
                }
                $type = $block['type'] ?? null;
                if ($type !== 'paragraph' && $type !== 'heading') {
                    throw UnsupportedSuggestionException::because(
                        sprintf('Table cell block [%s] is not textual; only textual tables are supported in v1.', is_string($type) ? $type : 'unknown'),
                        'non_textual_table',
                    );
                }
                $inlines = $block['content'] ?? [];
                if (is_array($inlines)) {
                    foreach ($inlines as $inline) {
                        if (! is_array($inline)) {
                            throw UnsupportedSuggestionException::because('Invalid inline in table cell.', 'non_textual_table');
                        }
                        $inlineType = $inline['type'] ?? null;
                        if ($inlineType !== 'text' && $inlineType !== 'hardBreak') {
                            throw UnsupportedSuggestionException::because(
                                sprintf('Inline node [%s] inside a table cell violates text_only table capability.', is_string($inlineType) ? $inlineType : 'unknown'),
                                'non_textual_table',
                            );
                        }
                        if (is_array($inline['marks'] ?? null)) {
                            foreach ($inline['marks'] as $mark) {
                                $markType = is_array($mark) ? ($mark['type'] ?? null) : null;
                                if (! is_string($markType) || ! $schema->allowsMark($markType)) {
                                    throw UnsupportedSuggestionException::because('Disallowed mark in table cell.', 'unsupported_mark');
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param array<array-key, mixed> $cell */
    public static function cellBlocks(array $cell): mixed
    {
        if (array_is_list($cell)) {
            return $cell;
        }

        return match ($cell['type'] ?? null) {
            'paragraph', 'heading' => [$cell],
            'tableCell', 'tableHeader', null => $cell['content'] ?? null,
            default => null,
        };
    }

    /** @param list<string> $urlAttrs */
    private function assertSafeAttrValue(string $nodeType, string $attr, mixed $value, array $urlAttrs): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertSafeAttrValue($nodeType, $attr, $item, $urlAttrs);
            }

            return;
        }
        if (! is_string($value)) {
            return;
        }
        if (in_array($attr, $urlAttrs, true)) {
            if (LinkSchemeValidator::sanitize($value) === null) {
                throw UnsupportedSuggestionException::because(
                    sprintf('Unsafe URL scheme in [%s.%s].', $nodeType, $attr),
                    'unsafe_url_attribute',
                );
            }

            return;
        }
        if (LinkSchemeValidator::isDangerous($value)) {
            throw UnsupportedSuggestionException::because(
                sprintf('Dangerous executable scheme rejected in [%s.%s].', $nodeType, $attr),
                'unsafe_attribute_value',
            );
        }
    }
}
