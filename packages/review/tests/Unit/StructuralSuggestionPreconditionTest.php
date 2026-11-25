<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Suggestions\SnapshotStructuralEdit;

// The package TestCase migrates on boot; see SuggestionOverlapDetectorTest.
uses(RefreshDatabase::class);

/**
 * A structural suggestion records the state it was made against. When the document
 * has moved on, applying it must fail closed with a conflict instead of editing
 * whatever now sits at the old coordinates.
 */
function structuralPreconditionDoc(): array
{
    $text = static fn (string $text, array $marks = []): array => ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
    $para = static fn (string $id, array $content): array => ['type' => 'paragraph', 'attrs' => ['blockId' => $id], 'content' => $content];
    $cell = static fn (string $id): array => ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$para($id, [$text($id)])]];

    return ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['blockId' => 'h1', 'level' => 1], 'content' => [$text('Title')]],
        $para('p1', [$text('Pay within '), $text('15', [['type' => 'bold']]), $text(' days.')]),
        $para('p2', [$text('Second')]),
        ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [
            ['type' => 'listItem', 'content' => [$para('li1', [$text('One')])]],
            ['type' => 'listItem', 'content' => [$para('li2', [$text('Two')])]],
        ]],
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [$cell('c11'), $cell('c12')]],
            ['type' => 'tableRow', 'content' => [$cell('c21'), $cell('c22')]],
        ]],
    ]];
}

function structuralPreconditionApply(StructuralSuggestionInput $input): array
{
    return app(SnapshotStructuralEdit::class)->apply(structuralPreconditionDoc(), $input);
}

/** @return list<mixed> */
function structuralPreconditionHead(): array
{
    return ['01a21300-0000-7000-8000-000000000001', '01a21300-0000-7000-8000-000000000002', 0, 0, 2];
}

it('rejects a stale structural suggestion as a conflict', function (Closure $make, string $message): void {
    expect(fn () => structuralPreconditionApply($make()))
        ->toThrow(SuggestionConflictException::class, $message);
})->with([
    'mark removal where the mark is gone' => [
        fn () => StructuralSuggestionInput::markChange(...structuralPreconditionHead(), targetBlockId: 'p1', from: 11, to: 13, markName: 'italic', operation: 'removed'),
        'The target mark is no longer present in the block.',
    ],
    'mark change whose quote no longer matches' => [
        fn () => StructuralSuggestionInput::markChange(...structuralPreconditionHead(), targetBlockId: 'p1', from: 11, to: 13, markName: 'italic', quoteText: '14'),
        'The target text range for mark_change has changed.',
    ],
    'mark change past the end of the block' => [
        fn () => StructuralSuggestionInput::markChange(...structuralPreconditionHead(), targetBlockId: 'p2', from: 2, to: 40, markName: 'italic'),
        'The target text range for mark_change has changed.',
    ],
    'mark change on a removed block' => [
        fn () => StructuralSuggestionInput::markChange(...structuralPreconditionHead(), targetBlockId: 'gone', from: 0, to: 2, markName: 'italic'),
        'The target text block is unavailable.',
    ],
    'heading level changed meanwhile' => [
        fn () => StructuralSuggestionInput::attrChange(...structuralPreconditionHead(), targetBlockId: 'h1', nodeType: 'heading', attr: 'level', oldValue: 3, newValue: 2),
        'The target attribute has changed.',
    ],
    'heading turned into a paragraph' => [
        fn () => StructuralSuggestionInput::attrChange(...structuralPreconditionHead(), targetBlockId: 'p1', nodeType: 'heading', attr: 'level', oldValue: 1, newValue: 2),
        'The target block type no longer matches attr_change.',
    ],
    'ordered list start changed meanwhile' => [
        fn () => StructuralSuggestionInput::attrChange(...structuralPreconditionHead(), targetBlockId: 'li1', nodeType: 'orderedList', attr: 'start', oldValue: 5, newValue: 7),
        'The target list attribute has changed.',
    ],
    'table cell span changed meanwhile' => [
        fn () => StructuralSuggestionInput::attrChange(...structuralPreconditionHead(), targetBlockId: 'c11', nodeType: 'tableCell', attr: 'colspan', oldValue: 2, newValue: 1, rowIndex: 0, colIndex: 0),
        'The target table cell attribute has changed.',
    ],
    'split past the end of the block' => [
        fn () => StructuralSuggestionInput::blockSplit(...structuralPreconditionHead(), targetBlockId: 'p2', offset: 99, splitKind: 'structural', newBlockId: 'p3'),
        'Split offset is outside the current block text.',
    ],
    'split that would reuse an existing block id' => [
        fn () => StructuralSuggestionInput::blockSplit(...structuralPreconditionHead(), targetBlockId: 'p2', offset: 2, splitKind: 'structural', newBlockId: 'h1'),
        'The target new_block_id already exists in the document.',
    ],
    'join of blocks that are no longer adjacent' => [
        fn () => StructuralSuggestionInput::blockJoin(...structuralPreconditionHead(), targetBlockId: 'h1', secondBlockId: 'p2'),
        'Adjacent second block does not match block_join precondition.',
    ],
    'join after the first block changed length' => [
        fn () => StructuralSuggestionInput::blockJoin(...structuralPreconditionHead(), targetBlockId: 'p1', secondBlockId: 'p2', joinOffsetUtf16: 3),
        'The target block length changed before block_join.',
    ],
    'indent of the first list item' => [
        fn () => StructuralSuggestionInput::listChange(...structuralPreconditionHead(), targetBlockId: 'li1', listOp: 'sink_item'),
        'First list item cannot be indented.',
    ],
    'lift of a paragraph that left the list' => [
        fn () => StructuralSuggestionInput::listChange(...structuralPreconditionHead(), targetBlockId: 'p2', listOp: 'lift'),
        'The target list item for lift is unavailable.',
    ],
    'list item split past its text' => [
        fn () => StructuralSuggestionInput::listChange(...structuralPreconditionHead(), targetBlockId: 'li2', listOp: 'split_item', newBlockId: 'li3', offset: 99),
        'Split offset is outside the list item paragraph.',
    ],
    'delete of a row that no longer exists' => [
        fn () => StructuralSuggestionInput::tableChange(...structuralPreconditionHead(), targetBlockId: 'c11', tableOp: 'delete_row', options: ['row_index' => 5]),
        'Target table row for deletion does not exist.',
    ],
    'delete of a column that no longer exists' => [
        fn () => StructuralSuggestionInput::tableChange(...structuralPreconditionHead(), targetBlockId: 'c11', tableOp: 'delete_column', options: ['col_index' => 5]),
        'Cannot delete column from table row.',
    ],
    'row insert past the end' => [
        fn () => StructuralSuggestionInput::tableChange(...structuralPreconditionHead(), targetBlockId: 'c11', tableOp: 'insert_row', options: ['row_index' => 5]),
        'Row index is out of bounds.',
    ],
]);

it('removes a mark only from the suggested range and merges the runs back', function (): void {
    $result = structuralPreconditionApply(StructuralSuggestionInput::markChange(
        ...structuralPreconditionHead(), targetBlockId: 'p1', from: 11, to: 13, markName: 'bold', operation: 'removed', quoteText: '15',
    ));

    expect($result['doc']['content'][1]['content'])->toBe([['type' => 'text', 'text' => 'Pay within 15 days.']])
        ->and($result['destroyedBlockIds'])->toBe([]);
});

it('drops a block attribute when the suggestion sets it to null', function (): void {
    $result = structuralPreconditionApply(StructuralSuggestionInput::attrChange(
        ...structuralPreconditionHead(), targetBlockId: 'li1', nodeType: 'orderedList', attr: 'start', oldValue: 1, newValue: null,
    ));

    expect($result['doc']['content'][3]['attrs'] ?? [])->not->toHaveKey('start');
});
