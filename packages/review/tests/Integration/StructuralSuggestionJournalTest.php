<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\RoomOwnership;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
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

    $seedPayload = 'seed-update';
    app(UpdateLog::class)->appendFenced(
        $docNode->id,
        new UpdateEnvelope(0, (string) Str::uuid(), $seedPayload, hash('sha256', $seedPayload), 'server', 1),
        $roomOwner,
        1,
        0,
        static fn () => null,
    );
}

function settleNextJournalCommand(string $nodeId, string $roomOwner, int $baseSeq, string $commitPayload): array
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
        updates: [
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

    // Writer rejects overlapping join through strict journal command using refreshed version after rebase
    $rejectCmd = 'cmd-reject-join-2';
    $pendingReject = $reviewWriter->reject($node->id, $overlapJoin->id, 0, $listAfter[$overlapJoin->id]->version, $rejectCmd);
    expect($pendingReject->isPendingJournal())->toBeTrue()
        ->and($pendingReject->journalCommandId)->toBe($rejectCmd);

    $settledReject = settleNextJournalCommand($node->id, $roomOwner, 2, 'journal-reject-join');
    expect($settledReject['state'])->toBe('committed');
    $listFinal = array_column($reviewOwner->list($node->id), null, 'id');
    expect($listFinal[$overlapJoin->id]->state)->toBe('rejected')
        ->and($listFinal[$overlapJoin->id]->journalState)->toBe('committed')
        ->and($listFinal[$overlapJoin->id]->committedPosition)->toBe(3)
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
