<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Data\AddAnchoredCommentInput;
use Vaulter\EditorPro\Review\Data\CommentAnchorInput;
use Vaulter\EditorPro\Review\Enums\AnchorState;
use Vaulter\EditorPro\Review\Enums\CommentFilterStatus;
use Vaulter\EditorPro\Review\Enums\SelectorKind;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;
use Vaulter\EditorPro\Review\Exceptions\InvalidCommentStateException;
use Vaulter\EditorPro\Review\ReviewManager;
use Vaulter\Exceptions\Concurrency\StaleWriteException;

function makeTextBlockDoc(string $text, string $blockId = 'blk_p42'): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => $blockId],
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
            ],
        ],
    ];
}

it('preserves plain node comments while adding generation-scoped range anchors and replies', function (): void {
    $commenter = AccessTestHelpers::user(1);
    $replier = AccessTestHelpers::user(2);

    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::COMMENTER, subjectId: 2);

    $drive1 = app(Vaulter::class)->drive($storage)->actingAs($commenter);
    $drive2 = app(Vaulter::class)->drive($storage)->actingAs($replier);
    $docs = app(DocumentsManager::class);
    $review = app(ReviewManager::class);

    $folder = $drive1->folders()->create('plain-folder');
    $doc = $docs->in($drive1)->create('range-doc', 'vaulter/rich-text', makeTextBlockDoc('Item price is 15 USD'));

    // Plain comment on folder via documents API still lists cleanly via review comments API.
    $plain = $docs->comments($drive1)->add($folder, new AddCommentInput('Folder note'));
    $folderList = $review->comments($drive1)->list($folder);
    expect($folderList->items)->toHaveCount(1)
        ->and($folderList->items[0]->comment->id)->toBe($plain->id)
        ->and($folderList->items[0]->anchor)->toBeNull();

    // Range-anchored comment on document.
    $root = $review->comments($drive1)->add($doc, new AddAnchoredCommentInput(
        body: 'Check amount',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_p42',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: [
                'from_utf16' => 14,
                'to_utf16' => 16,
                'prefix' => 'price is ',
                'suffix' => ' USD',
            ],
            quoteText: '15',
        ),
    ));

    // Threaded replies by user 2.
    $r1 = $review->comments($drive2)->add($doc, new AddAnchoredCommentInput('Agreed', parentId: $root->comment->id));
    $r2 = $review->comments($drive2)->add($doc, new AddAnchoredCommentInput('Wait for Q2', parentId: $root->comment->id));

    expect($root->anchor)->not->toBeNull()
        ->and($root->anchor->anchorState)->toBe(AnchorState::Attached)
        ->and($root->comment->authorId)->toBe('1')
        ->and($r1->comment->authorId)->toBe('2')
        ->and($r2->anchor)->toBeNull();

    // Filter buckets keep root and replies together.
    expect($review->comments($drive1)->list($doc, CommentFilterStatus::Active)->items)->toHaveCount(3)
        ->and($review->comments($drive1)->list($doc, CommentFilterStatus::Resolved)->items)->toHaveCount(0)
        ->and($review->comments($drive1)->list($doc, CommentFilterStatus::Detached)->items)->toHaveCount(0);
});

it('validates yjs_relative assoc polarity, rejects author spoofing, and blocks range anchors on replies', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $review = app(ReviewManager::class);
    $doc = $docs->in($drive)->create('yjs-validation', 'vaulter/rich-text', makeTextBlockDoc('hello world'));

    // Invalid assoc on start bound (must be 0).
    expect(fn () => $review->comments($drive)->add($doc, new AddAnchoredCommentInput(
        body: 'bad start assoc',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_p42',
            selectorKind: SelectorKind::YjsRelative,
            selectorPayload: [
                'start' => ['rel' => base64_encode('a'), 'assoc' => -1],
                'end' => ['rel' => base64_encode('b'), 'assoc' => -1],
            ],
            quoteText: 'hello',
        ),
    )))->toThrow(InvalidAnchorSelectorException::class);

    // Invalid spoof key inside selector_payload.
    expect(fn () => $review->comments($drive)->add($doc, new AddAnchoredCommentInput(
        body: 'spoof attempt',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_p42',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: [
                'from_utf16' => 0,
                'to_utf16' => 5,
                'author_subject' => 'user:999',
            ],
            quoteText: 'hello',
        ),
    )))->toThrow(InvalidAnchorSelectorException::class);

    // Valid HTTP creation ignoring any spoofed author field in body -> stores acting user '1'.
    $this->actingAs($owner);
    $res = $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments', [
        'body' => 'Session author only',
        'author' => ['type' => 'user', 'id' => '999'],
        'author_id' => '999',
        'anchor' => [
            'generation' => 0,
            'source_position' => 1,
            'schema_version' => 2,
            'block_id' => 'blk_p42',
            'selector_kind' => 'yjs_relative',
            'selector_payload' => [
                'start' => ['rel' => base64_encode('start'), 'assoc' => 0],
                'end' => ['rel' => base64_encode('end'), 'assoc' => -1],
            ],
            'quote_text' => 'hello',
        ],
    ])->assertCreated();

    $res->assertJsonPath('data.author.id', '1');
    $commentId = $res->json('data.id');

    $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments', [
        'body' => 'Reply from the sidebar',
        'parent_id' => $commentId,
        'anchor' => null,
    ])->assertCreated()
        ->assertJsonPath('data.parent_id', $commentId)
        ->assertJsonPath('data.anchor', null);

    $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments', [
        'body' => 'Incomplete selector',
        'anchor' => [],
    ])->assertUnprocessable();

    // Reply cannot carry a range anchor.
    expect(fn () => $review->comments($drive)->add($doc, new AddAnchoredCommentInput(
        body: 'reply with anchor',
        parentId: $commentId,
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_p42',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 0, 'to_utf16' => 5],
            quoteText: 'hello',
        ),
    )))->toThrow(InvalidCommentStateException::class);
});

it('enforces CAS on resolve, reopen, and reanchor races plus HTTP preconditions and idempotency', function (): void {
    $author = AccessTestHelpers::user(1);
    $editor = AccessTestHelpers::user(2);

    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::COMMENTER, subjectId: 1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 2);

    $driveEditor = app(Vaulter::class)->drive($storage)->actingAs($editor);
    $driveAuthor = app(Vaulter::class)->drive($storage)->actingAs($author);
    $docs = app(DocumentsManager::class);
    $review = app(ReviewManager::class);

    $doc = $docs->in($driveEditor)->create('cas-doc', 'vaulter/rich-text', makeTextBlockDoc('hello world'));
    $created = $review->comments($driveAuthor)->add($doc, new AddAnchoredCommentInput(
        body: 'Needs review',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_p42',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 0, 'to_utf16' => 5],
            quoteText: 'hello',
        ),
    ));

    $id = $created->comment->id;
    expect($created->comment->version)->toBe(0);

    // Concurrent resolve race: User 1 wins, User 2 fails with 412 StaleWriteException.
    $resolved = $review->comments($driveAuthor)->resolve($doc, $id, expectedVersion: 0);
    expect($resolved->comment->version)->toBe(1)
        ->and($resolved->isResolved())->toBeTrue();

    expect(fn () => $review->comments($driveEditor)->resolve($doc, $id, expectedVersion: 0))
        ->toThrow(StaleWriteException::class);

    // Concurrent reopen race: missing If-Match -> 428; wrong If-Match -> 412; valid If-Match -> 200 with v=2.
    $this->actingAs($editor);
    $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$id.'/reopen')
        ->assertStatus(428)
        ->assertJsonPath('code', 'precondition_required');

    $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$id.'/reopen', [], ['If-Match' => '"comment.'.$id.'.0"'])
        ->assertStatus(412)
        ->assertJsonPath('code', 'stale_write');

    $reopenKey = 'reopen-key-1';
    $reopenResp = $this->postJson(
        'api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$id.'/reopen',
        [],
        ['If-Match' => '"comment.'.$id.'.1"', 'Idempotency-Key' => $reopenKey],
    )->assertOk();

    expect($reopenResp->json('data.resolved_at'))->toBeNull()
        ->and($reopenResp->json('data.version'))->toBe(2)
        ->and($reopenResp->json('data.anchor.id'))->toBe($created->anchor->id);

    // Stale racer retrying reopen with old v=1 -> 412.
    expect(fn () => $review->comments($driveAuthor)->reopen($doc, $id, expectedVersion: 1))
        ->toThrow(StaleWriteException::class);

    // Idempotency replay of reopen even though tag moved from 1 to 2 -> replays committed v=2 receipt.
    $replayResp = $this->postJson(
        'api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$id.'/reopen',
        [],
        ['If-Match' => '"comment.'.$id.'.1"', 'Idempotency-Key' => $reopenKey],
    )->assertOk();
    expect($replayResp->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($replayResp->json('data.version'))->toBe(2);

    // CAS reanchor race on anchor version 1 -> 2.
    $anchorTag = $created->anchor->etag;
    $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$id.'/reanchor', [
        'generation' => 0,
        'source_position' => 2,
        'schema_version' => 2,
        'block_id' => 'blk_p42',
        'selector_kind' => 'snapshot_utf16',
        'selector_payload' => ['from_utf16' => 6, 'to_utf16' => 11],
        'quote_text' => 'world',
    ], ['If-Match' => $anchorTag])->assertOk()
        ->assertJsonPath('data.anchor.version', 2)
        ->assertJsonPath('data.anchor.quote_text', 'world');

    // Second reanchor using stale anchor tag (v=1) -> 412.
    $this->postJson('api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$id.'/reanchor', [
        'generation' => 0,
        'source_position' => 2,
        'schema_version' => 2,
        'block_id' => 'blk_p42',
        'selector_kind' => 'snapshot_utf16',
        'selector_payload' => ['from_utf16' => 0, 'to_utf16' => 5],
        'quote_text' => 'hello',
    ], ['If-Match' => $anchorTag])->assertStatus(412);

    $plain = $review->comments($driveAuthor)->add($doc, new AddAnchoredCommentInput('Initially unanchored'));
    $review->comments($driveAuthor)->resolve($doc, $plain->comment->id, expectedVersion: $plain->comment->version);
    $firstAnchorUrl = 'api/vaulter/v1/review/nodes/'.$doc->id.'/comments/'.$plain->comment->id.'/reanchor';
    $firstAnchor = [
        'generation' => 0,
        'source_position' => 1,
        'schema_version' => 2,
        'block_id' => 'blk_p42',
        'selector_kind' => 'snapshot_utf16',
        'selector_payload' => ['from_utf16' => 0, 'to_utf16' => 5],
        'quote_text' => 'hello',
    ];
    $this->postJson($firstAnchorUrl, $firstAnchor, [
        'If-Match' => $plain->etag,
        'Idempotency-Key' => 'stale-first-anchor',
    ])->assertStatus(412);
    $resolvedPlain = $review->comments($driveAuthor)->find($doc, $plain->comment->id);
    expect($resolvedPlain->anchor)->toBeNull();
    $this->postJson($firstAnchorUrl, $firstAnchor, ['If-Match' => $resolvedPlain->etag])
        ->assertOk()
        ->assertJsonPath('data.anchor.quote_text', 'hello');
});

it('rolls back the delegated comment if the generation changes after the preliminary lookup', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $documents = app(DocumentsManager::class)->in($drive);
    $review = app(ReviewManager::class)->comments($drive);
    $doc = $documents->create('anchor-after-restore', 'vaulter/rich-text', makeTextBlockDoc('hello world'));
    $revision = $documents->snapshot($doc, 'before comment');

    $restoredEpoch = null;
    $interleaved = false;
    $table = app(DatabaseOptions::class)->table('documents');
    app(VaulterDatabase::class)->connection()->listen(function (QueryExecuted $query) use ($doc, $documents, $revision, $table, &$interleaved, &$restoredEpoch): void {
        if (! $interleaved && str_contains($query->sql, '"collab_epoch"') && str_contains($query->sql, 'from "'.$table.'"')) {
            $interleaved = true;
            $restoredEpoch = $documents->restore($doc, $revision)->epoch;
        }
    });

    expect(fn () => $review->add($doc, new AddAnchoredCommentInput(
        body: 'Stale range',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_p42',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 0, 'to_utf16' => 5],
            quoteText: 'hello',
        ),
    )))->toThrow(InvalidCommentStateException::class);
    expect($restoredEpoch)->toBe(1)
        ->and($review->list($doc)->items)->toBe([]);
});

it('refuses to reopen a comment when the node is trashed after the preliminary authorization', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $doc = app(DocumentsManager::class)->in($drive)->create('reopen-after-trash', 'vaulter/rich-text', makeTextBlockDoc('hello'));
    $review = app(ReviewManager::class)->comments($drive);
    $comment = $review->add($doc, new AddAnchoredCommentInput('Resolved note'));
    $resolved = $review->resolve($doc, $comment->comment->id);

    $trashed = false;
    $table = app(DatabaseOptions::class)->table('node_comments');
    app(VaulterDatabase::class)->connection()->listen(function (QueryExecuted $query) use ($doc, $drive, $table, &$trashed): void {
        if (! $trashed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "'.$table.'"')) {
            $trashed = true;
            $drive->nodes()->trash($doc);
        }
    });

    expect(fn () => $review->reopen($doc, $resolved->comment->id, expectedVersion: $resolved->comment->version))
        ->toThrow(NodeNotFoundException::class);
    expect($trashed)->toBeTrue();
});

it('handles snapshot edit narrowing, full-deletion detach without losing replies, reanchor, and restore-detach', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $review = app(ReviewManager::class);

    // Notice UTF-16 test string includes an astral emoji before the target ("👋 Xhello world"):
    // "👋" = 2 UTF-16 code units, " X" = 2 UTF-16 code units -> "hello" starts at UTF-16 offset 4..9!
    $initialText = '👋 Xhello world';
    $oldDoc = makeTextBlockDoc($initialText, 'blk_main');
    $doc = $docs->in($drive)->create('snapshot-lifecycle', 'vaulter/rich-text', $oldDoc);

    $root = $review->comments($drive)->add($doc, new AddAnchoredCommentInput(
        body: 'Anchor comment',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_main',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: [
                'from_utf16' => 4,
                'to_utf16' => 9,
                'prefix' => '👋 X',
                'suffix' => ' world',
            ],
            quoteText: 'hello',
        ),
    ));
    $reply = $review->comments($drive)->add($doc, new AddAnchoredCommentInput('Keep this reply!', parentId: $root->comment->id));

    $deletedDuplicate = app(SnapshotAnchorResolver::class)->resolveOnEdit(
        $root->anchor,
        makeTextBlockDoc('👋 Xhello world then hello', 'blk_main'),
        makeTextBlockDoc('👋 Xworld then hello', 'blk_main'),
    );
    expect($deletedDuplicate->state)->toBe(AnchorState::Detached);

    // 1. Delete 'X' right before 'hello' + insert 'Z' immediately after 'hello' ("👋 helloZ world").
    // Start has assoc=0 and end has assoc=-1, so Z does NOT expand range!
    $shiftedDoc = makeTextBlockDoc('👋 helloZ world', 'blk_main');
    $docs->in($drive)->update($doc, $shiftedDoc);
    $afterShift = $review->comments($drive)->find($doc, $root->comment->id);
    expect($afterShift->anchor->anchorState)->toBe(AnchorState::Attached)
        ->and($afterShift->anchor->selectorPayload['from_utf16'])->toBe(3)
        ->and($afterShift->anchor->selectorPayload['to_utf16'])->toBe(8)
        ->and($afterShift->anchor->quoteText)->toBe('hello');

    // Insert at exact right edge: range still [3, 8] ("hello").
    $appendRightDoc = makeTextBlockDoc('👋 hello! world', 'blk_main');
    $docs->in($drive)->update($doc, $appendRightDoc);
    $afterAppend = $review->comments($drive)->find($doc, $root->comment->id);
    expect($afterAppend->anchor->selectorPayload['from_utf16'])->toBe(3)
        ->and($afterAppend->anchor->selectorPayload['to_utf16'])->toBe(8)
        ->and($afterAppend->anchor->quoteText)->toBe('hello');

    // 2. Partial deletion of leading "hel" -> range narrows to [3, 5] ("lo").
    $partialDelDoc = makeTextBlockDoc('👋 lo! world', 'blk_main');
    $docs->in($drive)->update($doc, $partialDelDoc);
    $afterPartial = $review->comments($drive)->find($doc, $root->comment->id);
    expect($afterPartial->anchor->anchorState)->toBe(AnchorState::Attached)
        ->and($afterPartial->anchor->selectorPayload['from_utf16'])->toBe(3)
        ->and($afterPartial->anchor->selectorPayload['to_utf16'])->toBe(5)
        ->and($afterPartial->anchor->quoteText)->toBe('lo');

    // 3. Complete deletion of target -> transitions to Detached, keeps quoteText ("lo") & sourcePosition & replies!
    $fullDelDoc = makeTextBlockDoc('👋 ! world', 'blk_main');
    $saved = $docs->in($drive)->update($doc, $fullDelDoc);
    expect($saved->epoch)->toBe(0);
    $afterFull = $review->comments($drive)->find($doc, $root->comment->id);
    expect($afterFull->anchor->anchorState)->toBe(AnchorState::Detached)
        ->and($afterFull->anchor->quoteText)->toBe('lo')
        ->and($afterFull->anchor->sourcePosition)->toBe(1);

    // Filter detached includes root + reply.
    $detachedList = $review->comments($drive)->list($doc, CommentFilterStatus::Detached);
    expect(array_map(static fn ($x): string => $x->comment->id, $detachedList->items))
        ->toContain($root->comment->id, $reply->comment->id);

    $firstPage = $review->comments($drive)->list($doc, CommentFilterStatus::Detached, new ListOptions(perPage: 1));
    expect($firstPage->items[0]->comment->id)->toBe($root->comment->id)
        ->and($firstPage->nextCursor)->not->toBeNull();
    $secondPage = $review->comments($drive)->list($doc, CommentFilterStatus::Detached, new ListOptions(perPage: 1, cursor: $firstPage->nextCursor));
    expect($secondPage->items)->toHaveCount(1)
        ->and($secondPage->items[0]->comment->id)->toBe($reply->comment->id);

    $review->comments($drive)->resolve($doc, $root->comment->id);
    $resolvedReply = $review->comments($drive)->list($doc, CommentFilterStatus::Resolved, new ListOptions(perPage: 1, cursor: $firstPage->nextCursor));
    expect($resolvedReply->items)->toHaveCount(1)
        ->and($resolvedReply->items[0]->comment->id)->toBe($reply->comment->id);

    // 4. Reanchor restores Attached state.
    $reattached = $review->comments($drive)->reanchor(
        $doc,
        $root->comment->id,
        new CommentAnchorInput(
            generation: 0,
            sourcePosition: 2,
            schemaVersion: 2,
            blockId: 'blk_main',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 5, 'to_utf16' => 10],
            quoteText: 'world',
        ),
        expectedAnchorVersion: $afterFull->anchor->version,
    );
    expect($reattached->anchor->anchorState)->toBe(AnchorState::Attached);

    // 5. Restore (G0 -> G1): RestoreAnchorTransitionSink atomically moves old generation anchors to Historical!
    $revBase = $docs->in($drive)->snapshot($doc, 'base');
    $currentDoc = $docs->in($drive)->content($doc);
    $restored = $docs->in($drive)->restore($doc, $revBase, expectedStateVersion: $currentDoc->stateVersion);
    expect($restored->epoch)->toBe(1);

    $afterRestore = $review->comments($drive)->find($doc, $root->comment->id);
    expect($afterRestore->anchor->anchorState)->toBe(AnchorState::Historical)
        ->and($afterRestore->isDetached())->toBeTrue();
    expect($review->comments($drive)->find($doc, $reply->comment->id)->comment->body)->toBe('Keep this reply!');

    // Old generation reanchor is refused; current generation (epoch=1) reanchor succeeds.
    expect(fn () => $review->comments($drive)->reanchor(
        $doc,
        $root->comment->id,
        new CommentAnchorInput(
            generation: 0,
            sourcePosition: 2,
            schemaVersion: 2,
            blockId: 'blk_main',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 0, 'to_utf16' => 5],
            quoteText: 'world',
        ),
    ))->toThrow(InvalidCommentStateException::class);

    $reanchoredG1 = $review->comments($drive)->reanchor(
        $doc,
        $root->comment->id,
        new CommentAnchorInput(
            generation: 1,
            sourcePosition: 1,
            schemaVersion: 2,
            blockId: 'blk_main',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 5, 'to_utf16' => 10],
            quoteText: 'world',
        ),
        expectedAnchorVersion: $afterRestore->anchor->version,
    );
    expect($reanchoredG1->anchor->anchorState)->toBe(AnchorState::Attached)
        ->and($reanchoredG1->anchor->generation)->toBe(1);

    // 6. Ambiguous snapshot remap ("alpha" appears twice without distinguishing context) -> Detached, keeps quoteText & reply!
    $ambigDoc = $docs->in($drive)->create('ambig-doc', 'vaulter/rich-text', makeTextBlockDoc('first item and target phrase', 'blk_amb'));
    $ambigRoot = $review->comments($drive)->add($ambigDoc, new AddAnchoredCommentInput(
        body: 'Watch duplicate quote',
        anchor: new CommentAnchorInput(
            generation: 0,
            sourcePosition: 3,
            schemaVersion: 2,
            blockId: 'blk_amb',
            selectorKind: SelectorKind::SnapshotUtf16,
            selectorPayload: ['from_utf16' => 15, 'to_utf16' => 21],
            quoteText: 'target',
        ),
    ));
    $ambigReply = $review->comments($drive)->add($ambigDoc, new AddAnchoredCommentInput('Do not lose me', parentId: $ambigRoot->comment->id));

    $ambigTargetDoc = makeTextBlockDoc('target and target elsewhere', 'blk_amb');
    $review->comments($drive)->reconcileSnapshot($ambigDoc, $ambigTargetDoc);
    $afterAmbig = $review->comments($drive)->find($ambigDoc, $ambigRoot->comment->id);
    expect($afterAmbig->anchor->anchorState)->toBe(AnchorState::Detached)
        ->and($afterAmbig->anchor->quoteText)->toBe('target')
        ->and($afterAmbig->anchor->sourcePosition)->toBe(3);
    expect($review->comments($drive)->find($ambigDoc, $ambigReply->comment->id)->comment->body)->toBe('Do not lose me');
});
