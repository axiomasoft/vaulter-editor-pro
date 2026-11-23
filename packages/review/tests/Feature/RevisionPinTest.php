<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\PinRevisionInput;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException;
use Vaulter\EditorPro\Review\ReviewManager;

function richDocContent(string $text, string $blockId = 'blk_intro'): array
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

it('pins a revision so pruning keeps it, serves preview/compare across compaction, and enforces current ACL', function (): void {
    config()->set('vaulter-documents.revisions.limit', 2);

    $owner = AccessTestHelpers::user(1);
    $reader = AccessTestHelpers::user(2);

    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode;
    AccessTestHelpers::grant($root, RoleRegistry::OWNER, subjectId: 1);

    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $review = app(ReviewManager::class);

    $docNode = $docs->in($ownerDrive)->create('contract', 'vaulter/rich-text', richDocContent('Version H text', 'blk_p42'));
    $readerGrant = AccessTestHelpers::grant($docNode, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 2);

    $revH = $docs->in($ownerDrive)->snapshot($docNode, 'H', keepForever: false);
    expect($revH->keepForever)->toBeFalse();

    $pin = $review->pins($ownerDrive)->pin($docNode, $revH, new PinRevisionInput(PinReason::ReviewBase));
    expect($pin->revisionId)->toBe($revH->id)
        ->and($pin->pinReason)->toBe(PinReason::ReviewBase);

    // Push past revisions.limit = 2 with unpinned checkpoints so compaction/pruning deletes intermediate rows.
    for ($i = 1; $i <= 4; $i++) {
        $head = $docs->in($ownerDrive)->content($docNode);
        $docs->in($ownerDrive)->update(
            $docNode,
            richDocContent('Version H+'.$i.' text', 'blk_p42'),
            expectedStateVersion: $head->stateVersion,
        );
        $docs->in($ownerDrive)->snapshot($docNode, 'Auto '.$i, keepForever: false);
    }

    // Pinned revision H must still survive and stay readable + comparable against working head.
    $allRevs = $docs->in($ownerDrive)->revisions($docNode);
    $revIds = array_map(static fn (DocumentRevisionSnapshot $r): string => $r->id, $allRevs->items);
    expect($revIds)->toContain($revH->id);

    $readerDrive = app(Vaulter::class)->drive($storage)->actingAs($reader);
    $preview = $review->pins($readerDrive)->preview($docNode, $revH);
    expect($preview->revision->id)->toBe($revH->id)
        ->and($preview->pin)->not->toBeNull()
        ->and($preview->content->toArray()['content'][0]['content'][0]['text'])->toBe('Version H text');

    $compare = $review->pins($readerDrive)->compare($docNode, $revH, null);
    expect($compare->from->id)->toBe($revH->id)
        ->and($compare->fromContent->toArray()['content'][0]['content'][0]['text'])->toBe('Version H text')
        ->and($compare->toContent->toArray()['content'][0]['content'][0]['text'])->toBe('Version H+4 text');

    // HTTP endpoints for preview, compare and pins respect current ACL.
    $this->actingAs($reader);
    $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/pins')
        ->assertOk()
        ->assertJsonPath('data.0.revision_id', $revH->id);
    $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/revisions/'.$revH->id.'/preview')
        ->assertOk()
        ->assertJsonPath('data.revision.id', $revH->id);
    $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/compare?from='.$revH->id.'&to=working')
        ->assertOk()
        ->assertJsonPath('data.from.meta.id', $revH->id)
        ->assertJsonPath('data.to.meta.kind', 'working');

    // Revoke reader grant: pinned revision MUST NOT leak via preview, compare, or pin list!
    $ownerDrive->access()->revoke($docNode->id, $readerGrant->id);
    $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/pins')
        ->assertNotFound()
        ->assertJsonPath('code', 'node_not_found');
    $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/revisions/'.$revH->id.'/preview')
        ->assertNotFound()
        ->assertJsonPath('code', 'node_not_found');
    $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/compare?from='.$revH->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'node_not_found');
});

it('rejects past expiry, supports expired pin cleanup, and scrubs unsafe link URLs on preview', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $review = app(ReviewManager::class);

    $docNode = $docs->in($drive)->create('xss-safe', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'blk_1'],
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'click',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://vaulter.test/ok']],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $rev = $docs->in($drive)->snapshot($docNode, 'v1', keepForever: false);

    expect(fn () => $review->pins($drive)->pin(
        $docNode,
        $rev,
        new PinRevisionInput(PinReason::ManualVersion, new DateTimeImmutable('-5 minutes')),
    ))->toThrow(InvalidAnchorSelectorException::class);

    $future = new DateTimeImmutable('+10 minutes');
    $review->pins($drive)->pin($docNode, $rev, new PinRevisionInput(PinReason::ManualVersion, $future));
    expect($review->pins($drive)->list($docNode))->toHaveCount(1);

    Carbon::setTestNow(Carbon::instance($future)->addMinute());
    try {
        expect($review->pins($drive)->list($docNode))->toHaveCount(0);
        expect($review->pins($drive)->releaseExpired($docNode, releaseRetention: true))->toBe(1);
    } finally {
        Carbon::setTestNow();
    }

    // Legacy revision payload with unsafe javascript: and control-char java\tscript: links is scrubbed on HTTP preview & compare.
    $legacyPayload = json_encode([
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'blk_1'],
                'content' => [
                    ['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                    ['type' => 'text', 'text' => 'obfuscated', 'marks' => [['type' => 'link', 'attrs' => ['href' => "java\tscript:alert(2)"]]]],
                    ['type' => 'text', 'text' => 'good', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://vaulter.test/ok']]]],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $db = app(VaulterDatabase::class);
    $tables = app(DatabaseOptions::class);
    $db->write(static function () use ($db, $tables, $rev, $legacyPayload): void {
        $db->connection()->table($tables->table('document_revision_payloads'))
            ->where('revision_id', $rev->id)
            ->update(['content' => $legacyPayload]);
    });

    $this->actingAs($owner);
    $previewJson = json_encode(
        $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/revisions/'.$rev->id.'/preview')
            ->assertOk()
            ->json('data.content'),
        JSON_THROW_ON_ERROR,
    );
    expect($previewJson)->not->toContain('javascript:')
        ->and($previewJson)->not->toContain('script:alert')
        ->and($previewJson)->toContain('https:\/\/vaulter.test\/ok');

    $compareJson = json_encode(
        $this->getJson('api/vaulter/v1/review/documents/'.$docNode->id.'/compare?from='.$rev->id)
            ->assertOk()
            ->json('data.from.content'),
        JSON_THROW_ON_ERROR,
    );
    expect($compareJson)->not->toContain('javascript:')
        ->and($compareJson)->not->toContain('script:alert')
        ->and($compareJson)->toContain('https:\/\/vaulter.test\/ok');

    // Reader cannot create ManualVersion pin (requires Edit) or unpin (requires Edit).
    $reader = AccessTestHelpers::user(9);
    AccessTestHelpers::grant($docNode, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 9);
    $readerDrive = app(Vaulter::class)->drive($storage)->actingAs($reader);
    expect(fn () => $review->pins($readerDrive)->pin($docNode, $rev, new PinRevisionInput(PinReason::ManualVersion)))
        ->toThrow(PermissionDeniedException::class);
});
