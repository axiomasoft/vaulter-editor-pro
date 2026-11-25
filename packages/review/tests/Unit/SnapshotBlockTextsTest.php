<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Anchors\SnapshotBlockTexts;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\SelectorKind;

// The package TestCase migrates on boot; see SuggestionOverlapDetectorTest.
uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function blockTextsParagraph(string $blockId, string ...$texts): array
{
    $content = [];
    foreach ($texts as $i => $text) {
        if ($i > 0) {
            $content[] = ['type' => 'hardBreak'];
        }
        $content[] = ['type' => 'text', 'text' => $text];
    }

    return ['type' => 'paragraph', 'attrs' => ['blockId' => $blockId], 'content' => $content];
}

/** @param  array<string, mixed>  $payload */
function blockTextsAnchor(string $blockId, int $from, int $to, string $quote, array $payload = []): CommentAnchorData
{
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    return new CommentAnchorData(
        id: 'a-'.$blockId.'-'.$from, commentId: 'c', documentId: 'd', generation: 1, sourcePosition: 0, schemaVersion: 1,
        blockId: $blockId, selectorKind: SelectorKind::SnapshotUtf16,
        selectorPayload: ['schema' => 1, 'block_id' => $blockId, 'from_utf16' => $from, 'to_utf16' => $to, 'prefix' => '', 'suffix' => ''] + $payload,
        quoteText: $quote, anchorState: AnchorState::Attached, version: 1, etag: 'e', createdAt: $now, updatedAt: $now,
    );
}

it('indexes the same block text a depth-first lookup finds', function (): void {
    $doc = ['type' => 'doc', 'content' => [
        blockTextsParagraph('p1', 'first', 'line'),
        ['type' => 'bulletList', 'content' => [[
            'type' => 'listItem',
            'attrs' => ['blockId' => 'dup'],
            'content' => [blockTextsParagraph('nested', 'inner'), blockTextsParagraph('dup', 'shadowed')],
        ]]],
        blockTextsParagraph('dup', 'later'),
        ['type' => 'paragraph', 'attrs' => 'not-an-array', 'content' => [['type' => 'text', 'text' => 'x']]],
    ]];

    $resolver = new SnapshotAnchorResolver;
    $index = SnapshotBlockTexts::of($doc);
    foreach (['p1', 'nested', 'dup', 'missing'] as $id) {
        expect($index->text($id))->toBe($resolver->findBlockTextById($doc, $id));
    }
    expect($index->text('p1'))->toBe("first\nline")
        ->and($index->text('dup'))->toBe('innershadowed')
        ->and($index->text('missing'))->toBeNull();
});

it('resolves every anchor of a write from the indexes exactly as from the documents', function (): void {
    $old = ['type' => 'doc', 'content' => [
        blockTextsParagraph('a', 'keep the quote here'),
        blockTextsParagraph('b', 'edit before the quote'),
        blockTextsParagraph('c', 'this block is deleted'),
        blockTextsParagraph('d', 'twin quote and twin quote'),
        blockTextsParagraph('e', 'quote moved'),
    ]];
    $new = ['type' => 'doc', 'content' => [
        blockTextsParagraph('a', 'keep the quote here'),
        blockTextsParagraph('b', 'we edit just before the quote'),
        blockTextsParagraph('d', 'twin quote, twin quote'),
        blockTextsParagraph('e', 'the quote moved'),
        blockTextsParagraph('f', 'a new block with a quote'),
    ]];
    $anchors = [
        blockTextsAnchor('a', 9, 14, 'quote'),
        blockTextsAnchor('b', 16, 21, 'quote'),
        blockTextsAnchor('c', 5, 10, 'block'),
        blockTextsAnchor('d', 5, 10, 'quote', ['prefix' => 'twin ', 'suffix' => ' and']),
        blockTextsAnchor('e', 0, 5, 'quote'),
        blockTextsAnchor('f', 17, 22, 'quote'),
    ];

    $resolver = new SnapshotAnchorResolver;
    $oldIndex = SnapshotBlockTexts::of($old);
    $newIndex = SnapshotBlockTexts::of($new);
    foreach ($anchors as $anchor) {
        expect($resolver->resolveBlockEdit($anchor, $oldIndex->text($anchor->blockId), $newIndex->text($anchor->blockId)))
            ->toEqual($resolver->resolveOnEdit($anchor, $old, $new))
            ->and($resolver->resolveBlock($anchor, $newIndex->text($anchor->blockId)))
            ->toEqual($resolver->resolveStateless($anchor, $new));
    }
});
