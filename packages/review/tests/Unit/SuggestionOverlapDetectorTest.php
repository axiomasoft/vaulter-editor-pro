<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Suggestions\SuggestionOverlapDetector;

// The package TestCase migrates on boot; without a transaction the teardown
// rollback of review migrations collides with exchange foreign keys on PostgreSQL.
uses(RefreshDatabase::class);

/** @param list<array<string, mixed>> $content */
function overlapNode(string $type, ?string $blockId, array $content = []): array
{
    $node = ['type' => $type];
    if ($blockId !== null) {
        $node['attrs'] = ['blockId' => $blockId];
    }
    if ($content !== []) {
        $node['content'] = $content;
    }

    return $node;
}

/** @param array<string, mixed> $payload */
function overlapSuggestion(string $id, string $kind, string $target, array $payload = []): TextSuggestion
{
    return new TextSuggestion(
        id: $id, groupId: 'g-'.$id, proposalId: null, documentId: 'doc', generation: 1, basePosition: 0,
        schemaVersion: 1, authorSubject: 'user', kind: $kind, targetBlockId: $target, from: 0, to: 0,
        parentId: null, conflicted: false, before: '', after: '', state: 'open', version: 1, receiptId: null,
        decidedBySubject: null, decidedAt: null, payload: $payload,
    );
}

/** Two lists, a list nested in the first, and a table. */
function overlapDocument(): array
{
    return overlapNode('doc', null, [
        overlapNode('bulletList', null, [
            overlapNode('listItem', 'a1', [overlapNode('paragraph', 'a1p')]),
            overlapNode('listItem', 'a2', [
                overlapNode('paragraph', 'a2p'),
                overlapNode('orderedList', 'n0', [overlapNode('listItem', 'n1', [overlapNode('paragraph', 'n1p')])]),
            ]),
        ]),
        overlapNode('paragraph', 'loose'),
        overlapNode('orderedList', null, [overlapNode('listItem', 'b1', [overlapNode('paragraph', 'b1p')])]),
        overlapNode('table', null, [
            overlapNode('tableRow', 'r0', [overlapNode('tableCell', 'c00'), overlapNode('tableCell', 'c01')]),
            overlapNode('tableRow', 'r1', [overlapNode('tableCell', 'c10')]),
        ]),
    ]);
}

it('names the innermost list or table around a block by its first block ID', function (string $kind, string $target, ?string $container): void {
    expect((new SuggestionOverlapDetector)->container(overlapSuggestion('s', $kind, $target), overlapDocument()))->toBe($container);
})->with([
    'list item' => ['list_change', 'a2p', 'a1'],
    'nested list' => ['list_change', 'n1p', 'n0'],
    'second list' => ['list_change', 'b1p', 'b1'],
    'outside any list' => ['list_change', 'loose', null],
    'table cell' => ['table_change', 'c10', 'r0'],
    'list block for a table change' => ['table_change', 'a1p', null],
    'unknown block' => ['list_change', 'missing', null],
]);

it('uses the first occurrence of a duplicated block ID that sits in a container', function (): void {
    $doc = overlapNode('doc', null, [
        overlapNode('paragraph', 'dup', [overlapNode('bulletList', null, [overlapNode('listItem', 'dup')])]),
        overlapNode('orderedList', null, [overlapNode('listItem', 'first'), overlapNode('listItem', 'dup')]),
    ]);

    // The copy nested inside the first `dup` is not considered; the later one inside the ordered list is.
    expect((new SuggestionOverlapDetector)->container(overlapSuggestion('s', 'list_change', 'dup'), $doc))->toBe('first');
});

it('rebuilds its container index when the document changes', function (): void {
    $detector = new SuggestionOverlapDetector;
    $moved = overlapDocument();
    $moved['content'][2]['content'][] = overlapNode('listItem', 'loose-now-listed');
    $a = overlapSuggestion('a', 'list_change', 'a1p');
    $b = overlapSuggestion('b', 'list_change', 'loose-now-listed');

    expect($detector->container($b, overlapDocument()))->toBeNull()
        ->and($detector->container($b, $moved))->toBe('b1')
        ->and($detector->container($b, overlapDocument()))->toBeNull()
        ->and($detector->overlaps($a, overlapSuggestion('c', 'list_change', 'n1p'), doc: overlapDocument()))->toBeFalse()
        ->and($detector->overlaps($a, overlapSuggestion('d', 'list_change', 'a2p'), doc: overlapDocument()))->toBeTrue()
        ->and($detector->overlaps($b, overlapSuggestion('e', 'list_change', 'b1p'), doc: $moved))->toBeTrue();
});

it('compares a large selection of list changes without walking the document per pair', function (): void {
    $lists = [];
    for ($list = 0; $list < 200; $list++) {
        $items = [];
        for ($item = 0; $item < 10; $item++) {
            $items[] = overlapNode('listItem', "l{$list}i{$item}", [overlapNode('paragraph', "l{$list}p{$item}")]);
        }
        $lists[] = overlapNode('bulletList', null, $items);
    }
    $doc = overlapNode('doc', null, $lists);
    $selection = [];
    for ($k = 0; $k < 80; $k++) {
        $selection[] = overlapSuggestion("s{$k}", 'list_change', 'l'.($k * 2).'p0');
    }

    $detector = new SuggestionOverlapDetector;
    $started = hrtime(true);
    foreach ($selection as $i => $a) {
        foreach (array_slice($selection, $i + 1) as $b) {
            expect($detector->overlaps($a, $b, doc: $doc))->toBeFalse();
        }
    }

    // 3160 pairs over 4000 blocks: ~5 ms with the index, several seconds with a tree walk per lookup.
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
});
