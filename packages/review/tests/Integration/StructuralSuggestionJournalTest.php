<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Collaboration\Persistence\Checkpoint;
use Vaulter\Collaboration\Persistence\CheckpointRequest;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Collaboration\Persistence\CompactionLeases;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\RoomOwnership;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\EditorPro\Review\ReviewManager;

function journalRichDoc(): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['blockId' => 'jh_1', 'level' => 1],
                'content' => [['type' => 'text', 'text' => 'Journal Title']],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'jp_1'],
                'content' => [['type' => 'text', 'text' => 'First clause']],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'jp_2'],
                'content' => [['type' => 'text', 'text' => 'Second clause']],
            ],
        ],
    ];
}

function enableStrictJournalRoom($docNode, string $roomOwner): void
{
    $db = app(VaulterDatabase::class);
    $tables = app(DatabaseOptions::class);

    $db->transaction(static function () use ($db, $tables, $docNode, $roomOwner): void {
        $db->connection()->table($tables->table('documents'))
            ->where('node_id', $docNode->id)
            ->update(['write_mode' => 'journal']);

        $locked = app(CollaborativeState::class)->lock($docNode->id);
        app(RoomOwnership::class)->selectProfile($locked, strict: true);
        app(RoomOwnership::class)->acquire($locked, $roomOwner);
    });

    $seed = 'seed-update';
    app(UpdateLog::class)->appendFenced(
        $docNode->id,
        new UpdateEnvelope(0, (string) Str::uuid(), $seed, hash('sha256', $seed), 'server', 1),
        $roomOwner, 1, 0, static fn () => null,
    );
    $marker = $db->transaction(static fn () => app(CollaborativeState::class)->lock($docNode->id)->pending);
    expect(app(CompactionLeases::class)->requestDrain($marker))->toBeTrue();
    publishReviewJournalCheckpoint($roomOwner);
}

function publishReviewJournalCheckpoint(string $roomOwner): void
{
    [$lease] = app(CompactionLeases::class)->grant($roomOwner, 1);
    app(Checkpoint::class)->publish(new CheckpointRequest(
        $lease->nodeId, 0, $lease->token, $lease->log->checkpointSeq, $lease->leaseThroughSeq, $lease->checkpointVersion,
        'seed-checkpoint', $lease->log->content, $lease->log->binding, $lease->log->type,
    ));
}

function settleNextJournalCommand(string $nodeId, string $roomOwner, int $baseSeq, ?string $commitPayload): array
{
    $db = app(VaulterDatabase::class);
    $nextIntent = $db->transaction(
        static fn (): ?array => app(CommandJournal::class)->next(app(CollaborativeState::class)->lock($nodeId)),
    );
    expect($nextIntent)->not->toBeNull();

    return app(CommandJournal::class)->settle(
        id: (string) $nextIntent['id'],
        owner: $roomOwner,
        fence: 1,
        generation: 0,
        base: $baseSeq,
        fingerprint: (string) $nextIntent['fingerprint'],
        updates: $commitPayload === null ? [] : [
            new UpdateEnvelope(
                0,
                (string) Str::uuid(),
                $commitPayload,
                hash('sha256', $commitPayload),
                'server',
                null,
            ),
        ],
        evidence: ['ranges' => []],
    );
}

it('registers guarded accepts and update-free rejects for every strict profile', function (): void {
    $storage = AccessTestHelpers::storage();
    $user = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($user);
    $docs = app(DocumentsManager::class)->in($drive);
    $review = app(ReviewManager::class)->suggestions($drive);
    $db = app(VaulterDatabase::class);
    $journal = app(CommandJournal::class);
    $scenarios = [
        'markChange' => ['jp_1', 0, 5, 'bold'],
        'attrChange' => ['jh_1', 'heading', 'level', 1, 2],
        'blockSplit' => ['jp_1', 5, 'structural', 'jp_new'],
        'blockJoin' => ['jp_1', 'jp_2', 12],
        'listChange' => ['jp_1', 'wrap_bullet'],
        'tableChange' => ['jp_1', 'insert_table', ['rows' => 1, 'cols' => 1, 'cell_block_ids' => ['cell']]],
    ];

    foreach ($scenarios as $factory => $args) {
        $node = $docs->create($factory, 'vaulter/rich-text', journalRichDoc());
        $owner = (string) Str::uuid();
        enableStrictJournalRoom($node, $owner);
        $next = fn (): array => $db->transaction(fn (): ?array => $journal->next(app(CollaborativeState::class)->lock($node->id)));
        $input = static fn (): StructuralSuggestionInput => StructuralSuggestionInput::$factory(
            (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, ...$args,
        );

        $draft = $review->save($node->id, $input());
        $review->reject($node->id, $draft->id, 0, $draft->version);
        expect($next()['intent']['atomic_effects'])->toBe([]);
        settleNextJournalCommand($node->id, $owner, 1, null);
        expect($review->find($node->id, $draft->id)->state)->toBe('rejected');

        $draft = $review->save($node->id, $input());
        $review->accept($node->id, $draft->id, 0, $draft->version);
        $intent = $next()['intent'];
        expect($intent['suggestions'][0]['author_subject'])->toBe($draft->authorSubject)
            ->and($intent['suggestions'][0]['kind'])->toBe($draft->kind)
            ->and($intent['atomic_effects'])->toHaveCount(1);
        [$effect] = $intent['atomic_effects'];
        expect($effect['effect'])->toBe('replace_review_document')
            ->and($effect['expected'])->toEqual(journalRichDoc())
            ->and($effect['replacement'])->not->toBe($effect['expected']);
        BlockIdentity::assertComplete($effect['replacement']);
        $receipt = settleNextJournalCommand($node->id, $owner, 1, 'committed-review-'.$factory);
        expect($review->find($node->id, $draft->id)->receiptId)->toBe($receipt['receipt_id']);
    }
});

it('routes structural accept, group accept, and reject through strict CommandJournal lifecycle and settles receipts', function (): void {
    $storage = AccessTestHelpers::storage();
    $ownerUser = AccessTestHelpers::user(1);
    $writerUser = AccessTestHelpers::user(2);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::WRITER, subjectId: 2);

    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($ownerUser);
    $writerDrive = app(Vaulter::class)->drive($storage)->actingAs($writerUser);
    $docs = app(DocumentsManager::class)->in($ownerDrive);
    $node = $docs->create('strict-journal-review', 'vaulter/rich-text', journalRichDoc());

    $roomOwner = (string) Str::uuid();
    enableStrictJournalRoom($node, $roomOwner);

    $reviewOwner = app(ReviewManager::class)->suggestions($ownerDrive);
    $reviewWriter = app(ReviewManager::class)->suggestions($writerDrive);
    $tables = app(DatabaseOptions::class);
    $db = app(VaulterDatabase::class);

    // Unsupported save fails BEFORE creating any journal command or DB row
    expect(fn () => $reviewOwner->save($node->id, StructuralSuggestionInput::attrChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'jp_1',
        nodeType: 'paragraph',
        attr: 'blockId',
        oldValue: 'jp_1',
        newValue: 'hacked',
    )))->toThrow(UnsupportedSuggestionException::class);
    expect($db->connection()->table($tables->table('collaboration_commands'))->count())->toBe(0);

    // Save two independent structural suggestions + one overlapping suggestion
    $attrGroupId = (string) Str::uuid7();
    $attrSugg = $reviewOwner->save($node->id, StructuralSuggestionInput::attrChange(
        id: (string) Str::uuid7(),
        groupId: $attrGroupId,
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'jh_1',
        nodeType: 'heading',
        attr: 'level',
        oldValue: 1,
        newValue: 2,
    ));

    $splitSugg = $reviewOwner->save($node->id, StructuralSuggestionInput::blockSplit(
        id: (string) Str::uuid7(),
        groupId: $attrGroupId,
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'jp_1',
        offset: 5,
        splitKind: 'structural',
        newBlockId: 'jp_split',
    ));

    $overlapJoin = $reviewWriter->save($node->id, StructuralSuggestionInput::blockJoin(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'jp_1',
        secondBlockId: 'jp_2',
        joinOffsetUtf16: 12,
    ));

    // Overlapping suggestion preserves writer authorship and cannot be accepted in journal mode
    expect($overlapJoin->conflicted)->toBeTrue()
        ->and($overlapJoin->conflictWithId)->toBe($splitSugg->id)
        ->and($overlapJoin->authorSubject)->toEndWith(':2')
        ->and($overlapJoin->authorSubject)->not->toBe($splitSugg->authorSubject);

    expect(fn () => $reviewWriter->accept($node->id, $overlapJoin->id, 0, $overlapJoin->version))
        ->toThrow(SuggestionConflictException::class);
    expect($db->connection()->table($tables->table('collaboration_commands'))->count())->toBe(0);

    // HTTP POST /groups/{group}/accept in strict journal mode returns 202 pending_commit
    $this->actingAs($ownerUser);
    $cmdId = 'cmd-group-accept-1';
    $groupRes = $this->withHeaders(['Idempotency-Key' => 'journal-group-1'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions/groups/'.$attrGroupId.'/accept', [
            'generation' => 0,
            'expected_versions' => [
                $attrSugg->id => $attrSugg->version,
                $splitSugg->id => $splitSugg->version,
            ],
            'command_id' => $cmdId,
        ])
        ->assertStatus(202)
        ->json('data');

    expect($groupRes)->toHaveCount(2)
        ->and($groupRes[0]['pending_commit'])->toBeTrue()
        ->and($groupRes[0]['journal_command_id'])->toBe($cmdId)
        ->and($groupRes[0]['state'])->toBe('open');

    // Idempotent replay before settlement returns same pending command state
    $replayPending = $reviewOwner->acceptGroup($node->id, $attrGroupId, 0, [
        $attrSugg->id => $attrSugg->version,
        $splitSugg->id => $splitSugg->version,
    ], $cmdId);
    expect($replayPending[0]->isPendingJournal())->toBeTrue()
        ->and($replayPending[0]->journalCommandId)->toBe($cmdId);

    // The exact request may replay, but a different decision, selection, version or key cannot reuse it.
    expect(fn () => $reviewOwner->rejectGroup($node->id, $attrGroupId, 0, [
        $attrSugg->id => $attrSugg->version, $splitSugg->id => $splitSugg->version,
    ], $cmdId))->toThrow(SuggestionConflictException::class)
        ->and(fn () => $reviewOwner->accept($node->id, $attrSugg->id, 0, $attrSugg->version, $cmdId))
        ->toThrow(SuggestionConflictException::class)
        ->and(fn () => $reviewOwner->acceptGroup($node->id, $attrGroupId, 0, [
            $attrSugg->id => $attrSugg->version + 1, $splitSugg->id => $splitSugg->version,
        ], $cmdId))->toThrow(SuggestionConflictException::class)
        ->and(fn () => $reviewOwner->reject($node->id, $attrSugg->id, 0, $attrSugg->version + 1, 'new-command'))
        ->toThrow(SuggestionConflictException::class);

    $amended = StructuralSuggestionInput::attrChange(
        id: $attrSugg->id, groupId: $attrGroupId, generation: 0, basePosition: 1, schemaVersion: 2,
        targetBlockId: 'jh_1', nodeType: 'heading', attr: 'level', oldValue: 1, newValue: 3,
    );
    expect(fn () => $reviewOwner->save($node->id, $amended, $attrSugg->version + 1))
        ->toThrow(SuggestionConflictException::class);

    // Room owner settles command 1
    $settled = settleNextJournalCommand($node->id, $roomOwner, 1, 'journal-apply-structural-group');
    expect($settled['state'])->toBe('committed')
        ->and($settled['receipt_id'])->toBeString();

    // Sync on list() settles both suggestions atomically with shared receiptId
    $listAfter = array_column($reviewOwner->list($node->id), null, 'id');
    expect($listAfter[$attrSugg->id]->state)->toBe('accepted')
        ->and($listAfter[$splitSugg->id]->state)->toBe('accepted')
        ->and($listAfter[$attrSugg->id]->receiptId)->toBe($settled['receipt_id'])
        ->and($listAfter[$splitSugg->id]->receiptId)->toBe($settled['receipt_id'])
        ->and($listAfter[$attrSugg->id]->isPendingJournal())->toBeFalse()
        ->and($listAfter[$attrSugg->id]->journalState)->toBe('committed')
        ->and($listAfter[$attrSugg->id]->committedPosition)->not->toBeNull();

    $replayCommitted = $reviewOwner->acceptGroup($node->id, $attrGroupId, 0, [
        $attrSugg->id => $attrSugg->version, $splitSugg->id => $splitSugg->version,
    ], $cmdId);
    expect($replayCommitted[0]->receiptId)->toBe($settled['receipt_id']);

    // Writer rejects overlapping join through strict journal command using refreshed version after rebase
    $rejectCmd = 'cmd-reject-join-2';
    $pendingReject = $reviewWriter->reject($node->id, $overlapJoin->id, 0, $listAfter[$overlapJoin->id]->version, $rejectCmd);
    expect($pendingReject->isPendingJournal())->toBeTrue()
        ->and($pendingReject->journalCommandId)->toBe($rejectCmd);

    $settledReject = settleNextJournalCommand($node->id, $roomOwner, 2, null);
    expect($settledReject['state'])->toBe('committed');
    $listFinal = array_column($reviewOwner->list($node->id), null, 'id');
    expect($listFinal[$overlapJoin->id]->state)->toBe('rejected')
        ->and($listFinal[$overlapJoin->id]->journalState)->toBe('committed')
        ->and($listFinal[$overlapJoin->id]->committedPosition)->toBe(2)
        ->and($listFinal[$overlapJoin->id]->authorSubject)->toEndWith(':2');
});

it('revokes access cleanly against pending and committed journal commands without mutating state', function (): void {
    $storage = AccessTestHelpers::storage();
    $ownerUser = AccessTestHelpers::user(1);
    $writerUser = AccessTestHelpers::user(2);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $writerGrant = AccessTestHelpers::grant($storage->rootNode, RoleRegistry::WRITER, subjectId: 2);

    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($ownerUser);
    $writerDrive = app(Vaulter::class)->drive($storage)->actingAs($writerUser);
    $docs = app(DocumentsManager::class)->in($ownerDrive);
    $node = $docs->create('strict-journal-acl', 'vaulter/rich-text', journalRichDoc());

    $roomOwner = (string) Str::uuid();
    enableStrictJournalRoom($node, $roomOwner);

    $reviewOwner = app(ReviewManager::class)->suggestions($ownerDrive);
    $reviewWriter = app(ReviewManager::class)->suggestions($writerDrive);

    $markSugg = $reviewWriter->save($node->id, StructuralSuggestionInput::markChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'jp_1',
        from: 0,
        to: 5,
        markName: 'bold',
        operation: 'added',
        quoteText: 'First',
    ));

    $pending = $reviewWriter->accept($node->id, $markSugg->id, 0, $markSugg->version, 'cmd-acl-1');
    expect($pending->isPendingJournal())->toBeTrue();

    // Writer loses permission after register/next; room owner settles command and CommandJournal records edit_revoked
    $db = app(VaulterDatabase::class);
    $nextIntent = $db->transaction(
        static fn (): ?array => app(CommandJournal::class)->next(app(CollaborativeState::class)->lock($node->id)),
    );
    expect($nextIntent)->not->toBeNull();

    $ownerDrive->access()->revoke($storage->rootNode->id, $writerGrant->id);

    $settledRevoked = app(CommandJournal::class)->settle(
        id: (string) $nextIntent['id'],
        owner: $roomOwner,
        fence: 1,
        generation: 0,
        base: 1,
        fingerprint: (string) $nextIntent['fingerprint'],
        updates: [
            new UpdateEnvelope(0, (string) Str::uuid(), 'revoked-commit', hash('sha256', 'revoked-commit'), 'server', null),
        ],
        evidence: ['ranges' => []],
    );
    expect($settledRevoked['state'])->toBe('rejected')
        ->and($settledRevoked['rejection_code'])->toBe('edit_revoked');

    // Owner sees suggestion remain open with pending_commit cleared (never falsely accepted);
    // Revoked writer cannot list or replay/accept anymore (NodeNotFoundException).
    $ownerItems = $reviewOwner->list($node->id);
    expect($ownerItems)->toHaveCount(1)
        ->and($ownerItems[0]->state)->toBe('open')
        ->and($ownerItems[0]->isPendingJournal())->toBeFalse()
        ->and($ownerItems[0]->receiptId)->toBeNull();

    expect(fn () => $reviewWriter->list($node->id))->toThrow(NodeNotFoundException::class);
    expect(fn () => $reviewWriter->accept($node->id, $markSugg->id, 0, $markSugg->version))
        ->toThrow(NodeNotFoundException::class);
});

it('isolates numeric journal command keys by deciding subject', function (): void {
    $storage = AccessTestHelpers::storage();
    $ownerUser = AccessTestHelpers::user(1);
    $writerUser = AccessTestHelpers::user(2);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::WRITER, subjectId: 2);
    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($ownerUser);
    $writerDrive = app(Vaulter::class)->drive($storage)->actingAs($writerUser);
    $node = app(DocumentsManager::class)->in($ownerDrive)->create('independent-commands', 'vaulter/rich-text', journalRichDoc());
    $roomOwner = (string) Str::uuid();
    enableStrictJournalRoom($node, $roomOwner);
    $owner = app(ReviewManager::class)->suggestions($ownerDrive);
    $writer = app(ReviewManager::class)->suggestions($writerDrive);
    $mark = static fn (string $block): StructuralSuggestionInput => StructuralSuggestionInput::markChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, $block, 0, 5, 'bold', 'added',
    );
    $first = $owner->save($node->id, $mark('jp_1'));
    $second = $writer->save($node->id, $mark('jp_2'));
    $owner->accept($node->id, $first->id, 0, $first->version, '123');
    $writer->reject($node->id, $second->id, 0, $second->version, '123');

    settleNextJournalCommand($node->id, $roomOwner, 1, 'first-command');
    $items = array_column($owner->list($node->id), null, 'id');
    expect($items[$first->id]->state)->toBe('accepted')
        ->and($items[$first->id]->decidedBySubject)->toEndWith(':1')
        ->and($items[$second->id]->isPendingJournal())->toBeTrue();

    settleNextJournalCommand($node->id, $roomOwner, 2, null);
    $result = $writer->reject($node->id, $second->id, 0, $second->version, '123');
    expect($result->state)->toBe('rejected')
        ->and($result->decidedBySubject)->toEndWith(':2')
        ->and($result->authorSubject)->toBe($second->authorSubject);
});

it('refuses structural drafts and decisions while the journal head cannot be verified', function (): void {
    $storage = AccessTestHelpers::storage();
    $user = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($user);
    $node = app(DocumentsManager::class)->in($drive)->create('unprojected-head', 'vaulter/rich-text', journalRichDoc());
    $roomOwner = (string) Str::uuid();
    enableStrictJournalRoom($node, $roomOwner);
    $review = app(ReviewManager::class)->suggestions($drive);
    $table = static fn (int $position): StructuralSuggestionInput => StructuralSuggestionInput::tableChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, $position, 2, 'jp_1', 'insert_table', ['rows' => 1, 'cols' => 1],
    );
    $draft = $review->save($node->id, $table(1));
    $bytes = 'unprojected-update';
    app(UpdateLog::class)->appendFenced($node->id,
        new UpdateEnvelope(0, (string) Str::uuid(), $bytes, hash('sha256', $bytes), 'server', null),
        $roomOwner, 1, 1, static fn () => null,
    );

    $projectionBehind = static function (SuggestionConflictException $conflict): void {
        expect($conflict->reason)->toBe('projection_behind');
    };
    $keyed = app(ReviewManager::class)->suggestions($drive->withIdempotencyKey('unprojected-draft'));
    $newDraft = $table(2);
    expect(fn () => $keyed->save($node->id, $newDraft))->toThrow($projectionBehind)
        ->and(fn () => $review->accept($node->id, $draft->id, 0, $draft->version))->toThrow($projectionBehind)
        ->and($review->list($node->id))->toHaveCount(1);
    expect(app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('collaboration_commands'))->count())->toBe(0);

    publishReviewJournalCheckpoint($roomOwner);
    expect($keyed->save($node->id, $newDraft)->basePosition)->toBe(2);
});

it('lists without locking the document once a rejected journal command left only its provenance', function (): void {
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $writerGrant = AccessTestHelpers::grant($storage->rootNode, RoleRegistry::WRITER, subjectId: 2);
    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs(AccessTestHelpers::user(1));
    $writerDrive = app(Vaulter::class)->drive($storage)->actingAs(AccessTestHelpers::user(2));
    $node = app(DocumentsManager::class)->in($ownerDrive)->create('rejected-provenance', 'vaulter/rich-text', journalRichDoc());
    $roomOwner = (string) Str::uuid();
    enableStrictJournalRoom($node, $roomOwner);
    $owner = app(ReviewManager::class)->suggestions($ownerDrive);
    $mark = static fn (string $block): StructuralSuggestionInput => StructuralSuggestionInput::markChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, $block, 0, 5, 'bold', 'added',
    );
    $decided = app(ReviewManager::class)->suggestions($writerDrive)->save($node->id, $mark('jp_1'));
    $owner->save($node->id, $mark('jp_2'));
    app(ReviewManager::class)->suggestions($writerDrive)->accept($node->id, $decided->id, 0, $decided->version, 'cmd-rejected');

    // The writer loses Edit before the room settles: the journal rejects the command (edit_revoked).
    $db = app(VaulterDatabase::class);
    $next = $db->transaction(static fn (): ?array => app(CommandJournal::class)->next(app(CollaborativeState::class)->lock($node->id)));
    $ownerDrive->access()->revoke($storage->rootNode->id, $writerGrant->id);
    $bytes = 'rejected-commit';
    expect(app(CommandJournal::class)->settle(
        id: (string) $next['id'], owner: $roomOwner, fence: 1, generation: 0, base: 1,
        fingerprint: (string) $next['fingerprint'],
        updates: [new UpdateEnvelope(0, (string) Str::uuid(), $bytes, hash('sha256', $bytes), 'server', null)],
        evidence: ['ranges' => []],
    )['state'])->toBe('rejected');

    // The first list settles the rejection; the suggestion is open again and keeps its provenance.
    expect(array_map(static fn ($item): array => [$item->state, $item->isPendingJournal()], $owner->list($node->id)))
        ->toBe([['open', false], ['open', false]]);

    $connection = $db->connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    expect($owner->list($node->id))->toHaveCount(2);
    $locking = array_filter(
        array_column($connection->getQueryLog(), 'query'),
        static fn (string $sql): bool => stripos($sql, 'for update') !== false || stripos($sql, 'advisory') !== false,
    );
    $connection->disableQueryLog();

    expect($locking)->toBe([]);
});

it('refuses journal drafts and accepts that are not on the journal head', function (): void {
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs(AccessTestHelpers::user(1));
    $node = app(DocumentsManager::class)->in($drive)->create('journal-head', 'vaulter/rich-text', journalRichDoc());
    $roomOwner = (string) Str::uuid();
    enableStrictJournalRoom($node, $roomOwner);
    $review = app(ReviewManager::class)->suggestions($drive);
    $table = static fn (int $position): StructuralSuggestionInput => StructuralSuggestionInput::tableChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, $position, 2, 'jp_1', 'insert_table', ['rows' => 1, 'cols' => 1],
    );

    expect(fn () => $review->save($node->id, $table(2)))
        ->toThrow(SuggestionConflictException::class, 'The journal suggestion base position is stale.');
    expect(fn () => $review->save($node->id, StructuralSuggestionInput::tableChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 1, 1, 2, 'jp_1', 'insert_table', ['rows' => 1, 'cols' => 1],
    )))->toThrow(SuggestionConflictException::class, 'The suggestion generation or schema version is stale.');
    $draft = $review->save($node->id, $table(1));

    // The journal moves on: the draft's base is no longer its head.
    $bytes = 'later-update';
    app(UpdateLog::class)->appendFenced($node->id,
        new UpdateEnvelope(0, (string) Str::uuid(), $bytes, hash('sha256', $bytes), 'server', null),
        $roomOwner, 1, 1, static fn () => null,
    );
    $marker = app(VaulterDatabase::class)->transaction(static fn () => app(CollaborativeState::class)->lock($node->id)->pending);
    expect(app(CompactionLeases::class)->requestDrain($marker))->toBeTrue();
    publishReviewJournalCheckpoint($roomOwner);

    expect(fn () => $review->accept($node->id, $draft->id, 0, $draft->version))
        ->toThrow(SuggestionConflictException::class, 'The journal suggestion base position is stale.')
        ->and(app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('collaboration_commands'))->count())->toBe(0);
});
