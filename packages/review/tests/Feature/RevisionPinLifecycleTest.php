<?php

declare(strict_types=1);

use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\PinRevisionInput;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\EditorPro\Review\Exceptions\RevisionPinNotFoundException;
use Vaulter\EditorPro\Review\ReviewManager;

/** @return array<string, mixed> */
function pinLifecycleDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'attrs' => ['blockId' => 'blk_1'], 'content' => [['type' => 'text', 'text' => $text]]],
    ]];
}

it('unpins a revision, optionally releasing the retention the pin set', function (bool $release): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $documents = app(DocumentsManager::class)->in($drive);
    $pins = app(ReviewManager::class)->pins($drive);
    $node = $documents->create('unpin', 'vaulter/rich-text', pinLifecycleDoc('Pinned text'));
    $revision = $documents->snapshot($node, 'v1', keepForever: false);

    $pins->pin($node, $revision, new PinRevisionInput(PinReason::ManualVersion));
    expect($pins->find($node, $revision)?->revisionId)->toBe($revision->id)
        ->and($documents->content($node, $revision)->revision?->keepForever)->toBeTrue();

    expect($pins->unpin($node, $revision, releaseRetention: $release))->toBeTrue()
        ->and($pins->find($node, $revision))->toBeNull()
        ->and($pins->list($node))->toBe([])
        ->and($documents->content($node, $revision)->revision?->keepForever)->toBe(! $release);

    // A second unpin has nothing to remove.
    expect(fn () => $pins->unpin($node, $revision))->toThrow(RevisionPinNotFoundException::class);
})->with(['keep retention' => false, 'release retention' => true]);

it('lets a reader see pins but not remove them', function (): void {
    $owner = AccessTestHelpers::user(1);
    $reader = AccessTestHelpers::user(2);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $documents = app(DocumentsManager::class)->in($ownerDrive);
    $node = $documents->create('reader-unpin', 'vaulter/rich-text', pinLifecycleDoc('Pinned text'));
    AccessTestHelpers::grant($node, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 2);
    $revision = $documents->snapshot($node, 'v1', keepForever: false);
    app(ReviewManager::class)->pins($ownerDrive)->pin($node, $revision, new PinRevisionInput(PinReason::ReviewBase));

    $readerPins = app(ReviewManager::class)->pins(app(Vaulter::class)->drive($storage)->actingAs($reader));

    expect($readerPins->find($node, $revision)?->revisionId)->toBe($revision->id)
        ->and(fn () => $readerPins->unpin($node, $revision, releaseRetention: true))->toThrow(PermissionDeniedException::class)
        ->and($readerPins->find($node, $revision))->not->toBeNull()
        ->and($documents->content($node, $revision)->revision?->keepForever)->toBeTrue();
});
