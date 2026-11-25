<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Data\CommentAnchorData;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\SelectorKind;

// The package TestCase migrates on boot; see SuggestionOverlapDetectorTest.
uses(RefreshDatabase::class);

/*
 * How a comment anchor follows its quote when the block text changed under it: a unique match
 * moves the anchor, several matches are told apart by the stored prefix and suffix, and anything
 * still ambiguous detaches the comment rather than pinning it to the wrong words.
 */

function recoveryAnchor(int $from, string $quote, string $prefix = '', string $suffix = '', AnchorState $state = AnchorState::Attached, SelectorKind $kind = SelectorKind::SnapshotUtf16): CommentAnchorData
{
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $to = $from + intdiv(strlen((string) mb_convert_encoding($quote, 'UTF-16LE', 'UTF-8')), 2);

    return new CommentAnchorData(
        id: 'a', commentId: 'c', documentId: 'd', generation: 1, sourcePosition: 0, schemaVersion: 1,
        blockId: 'p1', selectorKind: $kind,
        selectorPayload: ['schema' => 1, 'block_id' => 'p1', 'from_utf16' => $from, 'to_utf16' => $to, 'prefix' => $prefix, 'suffix' => $suffix],
        quoteText: $quote, anchorState: $state, version: 1, etag: 'e', createdAt: $now, updatedAt: $now,
    );
}

it('keeps an anchor whose quote is still at its offsets', function (): void {
    $result = (new SnapshotAnchorResolver)->resolveBlock(recoveryAnchor(4, 'within'), 'Pay within 15 days');

    expect($result->state)->toBe(AnchorState::Attached)
        ->and($result->changed)->toBeFalse();
});

it('moves an anchor to the only remaining occurrence of its quote, counting UTF-16 units', function (): void {
    // "😀 " is three UTF-16 units, so the quote moved from offset 4 to 7.
    $result = (new SnapshotAnchorResolver)->resolveBlock(recoveryAnchor(4, 'within'), '😀 Pay within 15 days');

    expect($result->state)->toBe(AnchorState::Attached)
        ->and($result->changed)->toBeTrue()
        ->and($result->selectorPayload['from_utf16'])->toBe(7)
        ->and($result->selectorPayload['to_utf16'])->toBe(13)
        ->and($result->selectorPayload['prefix'])->toBe('😀 Pay ')
        ->and($result->selectorPayload['suffix'])->toBe(' 15 days');
});

it('tells repeated quotes apart by the stored prefix or suffix', function (string $prefix, string $suffix, int $expected): void {
    $text = 'Net 15 days for goods, net 15 weeks for services';
    $result = (new SnapshotAnchorResolver)->resolveBlock(recoveryAnchor(0, '15', $prefix, $suffix), $text);

    expect($result->state)->toBe(AnchorState::Attached)
        ->and($result->selectorPayload['from_utf16'])->toBe($expected)
        ->and(mb_substr($text, $expected, 2))->toBe('15');
})->with([
    'prefix picks the second' => ['goods, net ', '', 27],
    'suffix picks the first' => ['', ' days', 4],
    'both agree on the second' => ['net ', ' weeks', 27],
]);

it('detaches instead of guessing when the quote is ambiguous or gone', function (string $prefix, string $suffix, string $text): void {
    $anchor = recoveryAnchor(0, '15', $prefix, $suffix);
    $result = (new SnapshotAnchorResolver)->resolveBlock($anchor, $text);

    expect($result->state)->toBe(AnchorState::Detached)
        ->and($result->changed)->toBeTrue()
        // The comment keeps what it pointed at, so a person can re-anchor it.
        ->and($result->quoteText)->toBe('15')
        ->and($result->selectorPayload)->toBe($anchor->selectorPayload);
})->with([
    'two matches, no context' => ['', '', 'Net 15 days, net 15 weeks'],
    'context matches both' => ['et ', '', 'Net 15 days, net 15 weeks'],
    'context matches neither' => ['gross ', '', 'Net 15 days, net 15 weeks'],
    'quote deleted' => ['', '', 'Net thirty days'],
]);

it('detaches when the block is gone and leaves detached or Yjs anchors alone', function (): void {
    $resolver = new SnapshotAnchorResolver;

    expect($resolver->resolveBlock(recoveryAnchor(4, 'within'), null)->state)->toBe(AnchorState::Detached);

    foreach ([recoveryAnchor(4, 'within', state: AnchorState::Detached), recoveryAnchor(4, 'within', kind: SelectorKind::YjsRelative)] as $anchor) {
        $result = $resolver->resolveBlock($anchor, 'something else entirely');
        expect($result->state)->toBe($anchor->anchorState)
            ->and($result->changed)->toBeFalse();
    }
});
