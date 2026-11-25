<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Enums\ListOperation;
use Vaulter\EditorPro\Review\Enums\TableOperation;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\EditorPro\Review\Suggestions\ReviewCapabilities;
use Vaulter\EditorPro\Review\Suggestions\SnapshotStructuralEdit;

// The package TestCase migrates on boot; see SuggestionOverlapDetectorTest.
uses(RefreshDatabase::class);

it('advertises exactly the list and table operations the editor can apply', function (): void {
    expect(ReviewCapabilities::LIST_OPERATIONS)->toBe(array_column(ListOperation::cases(), 'value'))
        ->and(ReviewCapabilities::TABLE_OPERATIONS)->toBe(array_column(TableOperation::cases(), 'value'));
});

it('refuses to apply a table suggestion with an unknown operation', function (): void {
    $cell = static fn (string $id): array => ['type' => 'tableCell', 'content' => [
        ['type' => 'paragraph', 'attrs' => ['blockId' => $id], 'content' => [['type' => 'text', 'text' => $id]]],
    ]];
    $doc = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
        ['type' => 'tableRow', 'content' => [$cell('c11'), $cell('c12')]],
    ]]]];
    $suggestion = StructuralSuggestionInput::tableChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 0, 2, 'c11', 'drop_table', ['row_index' => 0, 'col_index' => 0],
    );

    expect(fn () => app(SnapshotStructuralEdit::class)->apply($doc, $suggestion))
        ->toThrow(UnsupportedSuggestionException::class, 'Table operation [drop_table] is not supported.');
});
