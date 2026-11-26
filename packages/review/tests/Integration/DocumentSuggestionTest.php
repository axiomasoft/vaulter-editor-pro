<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Contracts\PendingSuggestionReservations;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\EditorPro\Review\Operations\SuggestionOperations;
use Vaulter\EditorPro\Review\ReviewManager;

beforeEach(function (): void {
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $this->storage = $storage;
    $this->root = $storage->rootNode;
    $this->grant = AccessTestHelpers::grant($this->root, RoleRegistry::WRITER, subjectId: 2);
    $vaulter = app(Vaulter::class);
    $this->owner = $vaulter->drive($storage)->actingAs(AccessTestHelpers::user(1));
    $this->editor = $vaulter->drive($storage)->actingAs(AccessTestHelpers::user(2));
    $this->docs = app(DocumentsManager::class)->in($this->owner);
    $this->node = $this->docs->create('tracked', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'review_block'],
            'content' => [
                ['type' => 'text', 'text' => '🦊 red', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' blue'],
            ],
        ]],
    ]);
    $this->first = app(ReviewManager::class)->suggestions($this->owner);
    $this->second = app(ReviewManager::class)->suggestions($this->editor);
});

function reviewTextEdit(
    int $from,
    int $to,
    string $before,
    string $after,
    ?string $proposalId = null,
    ?string $parentId = null,
    ?string $groupId = null,
): TextSuggestionInput {
    return new TextSuggestionInput(
        id: (string) Str::uuid7(),
        groupId: $groupId ?? (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'review_block',
        from: $from,
        to: $to,
        before: $before,
        after: $after,
        proposalId: $proposalId,
        parentId: $parentId,
    );
}

function acceptedReviewText($docs, $node): string
{
    return implode('', array_column($docs->content($node)->content->toArray()['content'][0]['content'] ?? [], 'text'));
}

it('accepts a replacement group once, rebases independent edits, and preserves open overlaps', function (): void {
    $replace = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'green'));
    $overlap = $this->second->save($this->node->id, reviewTextEdit(4, 8, 'ed b', ''));
    $independent = $this->second->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'gold'));
    expect($replace->authorSubject)->not->toBe($overlap->authorSubject)
        ->and(acceptedReviewText($this->docs, $this->node))->toBe('🦊 red blue');

    $selection = [$replace->id => 1];
    $accepted = $this->first->acceptGroup($this->node->id, $replace->groupId, 0, $selection);
    expect($accepted[0]->receiptId)->not->toBeNull()
        ->and($this->first->acceptGroup($this->node->id, $replace->groupId, 0, $selection))->toEqual($accepted)
        ->and(acceptedReviewText($this->docs, $this->node))->toBe('🦊 green blue');

    $open = array_column($this->second->list($this->node->id), null, 'id');
    expect($open[$overlap->id]->state)->toBe('open')
        ->and($open[$overlap->id]->conflicted)->toBeTrue()
        ->and($open[$independent->id]->from)->toBe(9);
    expect(fn () => $this->second->accept($this->node->id, $overlap->id, 0, $open[$overlap->id]->version))
        ->toThrow(SuggestionConflictException::class);
    $this->second->reject($this->node->id, $overlap->id, 0, $open[$overlap->id]->version);
    expect(acceptedReviewText($this->docs, $this->node))->toBe('🦊 green blue');
    $this->second->accept($this->node->id, $independent->id, 0, $open[$independent->id]->version);
    expect(acceptedReviewText($this->docs, $this->node))->toBe('🦊 green gold');
    expect($this->docs->content($this->node)->content->toArray()['content'][0]['content'][0]['marks'])
        ->toBe([['type' => 'bold']]);
});

it('cascades nested rejection, protects proposal decisions, and reauthorizes repeat calls', function (): void {
    $parent = $this->first->save($this->node->id, reviewTextEdit(6, 6, '', ' bag'));
    $child = $this->second->save($this->node->id, reviewTextEdit(1, 3, 'ba', 'ru', parentId: $parent->id));
    $proposalId = (string) Str::uuid7();
    $proposal = $this->first->save($this->node->id, reviewTextEdit(0, 2, '🦊', '🦉', proposalId: $proposalId));
    $otherProposal = $this->second->save($this->node->id, reviewTextEdit(0, 2, '🦊', '🦝', proposalId: (string) Str::uuid7()));
    expect($this->first->list($this->node->id, $proposalId)[0]->id)->toBe($proposal->id)
        ->and($this->first->list($this->node->id, $proposalId))->toHaveCount(1)
        ->and(fn () => $this->first->accept($this->node->id, $proposal->id, 0, 1))->toThrow(SuggestionConflictException::class)
        ->and(fn () => $this->second->reject($this->node->id, $otherProposal->id, 0, 1))->toThrow(SuggestionConflictException::class);

    $this->first->reject($this->node->id, $parent->id, 0, 1);
    expect(array_column($this->first->list($this->node->id), 'state', 'id')[$child->id])->toBe('superseded')
        ->and(acceptedReviewText($this->docs, $this->node))->toBe('🦊 red blue');

    $accepted = $this->second->save($this->node->id, reviewTextEdit(3, 6, 'red', 'rose'));
    $pending = $this->second->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'navy'));
    $this->second->accept($this->node->id, $accepted->id, 0, 1);
    $this->owner->access()->revoke($this->root->id, $this->grant->id);
    expect(fn () => $this->second->accept($this->node->id, $accepted->id, 0, 1))->toThrow(NodeNotFoundException::class)
        ->and(fn () => $this->second->reject($this->node->id, $pending->id, 0, 2))->toThrow(NodeNotFoundException::class);
});

it('enforces the database receipt and text range invariants', function (): void {
    expect(fn () => $this->first->save($this->node->id, reviewTextEdit(1, 1, '', '!')))
        ->toThrow(SuggestionConflictException::class);
    $draft = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'rose'));
    $table = app(VaulterDatabase::class)->connection()->table('v_document_suggestions')->where('id', $draft->id);
    expect(fn () => $table->update(['state' => 'accepted']))->toThrow(QueryException::class);
});

it('uses one receipt for a complete group and rolls back text if recording the receipt fails', function (): void {
    $group = (string) Str::uuid7();
    $one = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'green', groupId: $group));
    $two = $this->first->save($this->node->id, reviewTextEdit(7, 11, 'blue', '', groupId: $group));
    $versions = [$one->id => 1, $two->id => 1];
    expect(fn () => $this->first->acceptGroup($this->node->id, $group, 0, [$one->id => 1]))
        ->toThrow(SuggestionConflictException::class);

    $db = app(VaulterDatabase::class)->connection();
    $driver = $db->getDriverName();
    if ($driver === 'pgsql') {
        $db->unprepared("CREATE FUNCTION abort_review_write() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'receipt write failed'; END $$");
        $db->unprepared('CREATE TRIGGER abort_review_write BEFORE UPDATE ON v_document_suggestions FOR EACH ROW EXECUTE FUNCTION abort_review_write()');
    } elseif ($driver === 'sqlite') {
        $db->unprepared("CREATE TRIGGER abort_review_write BEFORE UPDATE ON v_document_suggestions BEGIN SELECT RAISE(ABORT, 'receipt write failed'); END");
    } else {
        $db->unprepared("CREATE TRIGGER abort_review_write BEFORE UPDATE ON v_document_suggestions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receipt write failed'");
    }
    try {
        expect(fn () => $this->first->acceptGroup($this->node->id, $group, 0, $versions))->toThrow(QueryException::class, 'receipt write failed');
        expect(acceptedReviewText($this->docs, $this->node))->toBe('🦊 red blue')
            ->and(array_column($this->first->list($this->node->id), 'state'))->toBe(['open', 'open']);
    } finally {
        if ($driver === 'pgsql') {
            $db->unprepared('DROP TRIGGER abort_review_write ON v_document_suggestions');
            $db->unprepared('DROP FUNCTION abort_review_write()');
        } else {
            $db->unprepared('DROP TRIGGER abort_review_write');
        }
    }
    $resolved = $this->first->acceptGroup($this->node->id, $group, 0, $versions);
    expect(array_unique(array_column($resolved, 'receiptId')))->toHaveCount(1)
        ->and($resolved[0]->receiptId)->not->toBeNull()
        ->and(acceptedReviewText($this->docs, $this->node))->toBe('🦊 green ');
});

test('bulk accept/reject (all, by_user, in_range) and stale-generation suggestions work', function (): void {
    $s1 = $this->first->save($this->node->id, reviewTextEdit(0, 2, '🦊', '🐱'));
    $s2 = $this->second->save($this->node->id, reviewTextEdit(3, 6, 'red', 'gold'));
    $s3 = $this->first->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'cyan'));

    $byUser = $this->first->acceptByUser($this->node->id, 0, $s2->authorSubject);
    expect($byUser)->toHaveCount(1)
        ->and($byUser[0]->id)->toBe($s2->id)
        ->and($byUser[0]->state)->toBe('accepted');

    $inRange = $this->first->rejectInRange($this->node->id, 0, 7, 11);
    expect($inRange)->toHaveCount(1)
        ->and($inRange[0]->id)->toBe($s3->id)
        ->and($inRange[0]->state)->toBe('rejected');

    $rest = $this->first->acceptAll($this->node->id, 0);
    expect($rest)->toHaveCount(1)
        ->and($rest[0]->id)->toBe($s1->id)
        ->and($rest[0]->state)->toBe('accepted');

    // Stale generation after restore: simulate generation bump on document
    $pos = $this->docs->metadata($this->node)->stateVersion;
    $stale = $this->first->save($this->node->id, new TextSuggestionInput(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: $pos,
        schemaVersion: 2,
        targetBlockId: 'review_block',
        from: 0,
        to: 0,
        before: '',
        after: 'new ',
    ));
    $db = app(VaulterDatabase::class)->connection();
    $db->table('v_documents')->where('id', $stale->documentId)->update(['collab_epoch' => 1]);

    expect($this->first->list($this->node->id))->toBe([]);
    $withStale = $this->first->list($this->node->id, includeStaleGenerations: true);
    expect($withStale)->toHaveCount(1)
        ->and($withStale[0]->id)->toBe($stale->id)
        ->and($withStale[0]->generationChanged)->toBeTrue();

    expect(fn () => $this->first->accept($this->node->id, $stale->id, 0, $stale->version))
        ->toThrow(SuggestionConflictException::class);

    expect(fn () => $this->first->reject($this->node->id, $stale->id, 0, $stale->version + 1))
        ->toThrow(SuggestionConflictException::class, 'The stale-generation suggestion could not be rejected.');

    $rejectedStale = $this->first->reject($this->node->id, $stale->id, 0, $stale->version);
    expect($rejectedStale->state)->toBe('rejected')
        ->and($rejectedStale->conflictReason)->toBe('generation_changed');
});

/** A draft like reviewTextEdit() with any of its fields replaced. */
function reviewDraft(array $fields): TextSuggestionInput
{
    $edit = reviewTextEdit(3, 6, 'red', 'rose');

    return new TextSuggestionInput(...[
        'id' => $edit->id, 'groupId' => $edit->groupId, 'generation' => $edit->generation, 'basePosition' => $edit->basePosition,
        'schemaVersion' => $edit->schemaVersion, 'targetBlockId' => $edit->targetBlockId, 'from' => $edit->from, 'to' => $edit->to,
        'before' => $edit->before, 'after' => $edit->after, ...$fields,
    ]);
}

it('refuses a draft whose base position or schema version is not the document\'s', function (array $fields): void {
    expect(fn () => $this->first->save($this->node->id, reviewDraft($fields)))
        ->toThrow(SuggestionConflictException::class);
    expect($this->first->list($this->node->id))->toBe([]);
})->with([
    'base position' => [['basePosition' => 2]],
    'schema version' => [['schemaVersion' => 1]],
]);

it('extends a group only with its author\'s open drafts and keeps a parent\'s text while a nested edit is open', function (): void {
    $draft = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'rose'));
    expect(fn () => $this->second->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'gold', groupId: $draft->groupId)))
        ->toThrow(SuggestionConflictException::class, 'This group cannot be extended.');

    $parent = $this->first->save($this->node->id, reviewTextEdit(6, 6, '', ' bag'));
    $this->second->save($this->node->id, reviewTextEdit(1, 3, 'ba', 'ru', parentId: $parent->id));
    expect(fn () => $this->first->save($this->node->id, reviewDraft([
        'id' => $parent->id, 'groupId' => $parent->groupId, 'from' => 6, 'to' => 6, 'before' => '', 'after' => ' bog',
    ]), 1))->toThrow(SuggestionConflictException::class, 'Resolve the nested edit before changing its parent text.');
});

it('nests an edit only inside an open, plain insertion of the same block', function (): void {
    $proposal = $this->first->save($this->node->id, reviewTextEdit(6, 6, '', ' bag', proposalId: (string) Str::uuid7()));
    $rejected = $this->first->save($this->node->id, reviewTextEdit(11, 11, '', ' cap'));
    $this->first->reject($this->node->id, $rejected->id, 0, 1);

    foreach (['proposal' => $proposal, 'rejected' => $rejected] as $label => $parent) {
        expect(fn () => $this->second->save($this->node->id, reviewTextEdit(1, 3, substr($parent->after, 1, 2), 'xx', parentId: $parent->id)))
            ->toThrow(SuggestionConflictException::class, 'The parent insertion is unavailable at the supplied text range.');
    }
});

it('refuses a draft on an archived drive', function (): void {
    $this->storage->forceFill(['status' => 'archived'])->save();
    $archived = app(Vaulter::class)->drive($this->storage->fresh())->actingAs(AccessTestHelpers::user(1));

    expect(fn () => app(ReviewManager::class)->suggestions($archived)->save($this->node->id, reviewTextEdit(3, 6, 'red', 'rose')))
        ->toThrow(DriveArchivedException::class);
});

it('does not accept overlapping edits together', function (): void {
    $first = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'green'));
    $second = $this->first->save($this->node->id, reviewTextEdit(4, 8, 'ed b', '', groupId: $first->groupId));

    expect(fn () => $this->first->acceptGroup($this->node->id, $first->groupId, 0, [$first->id => 1, $second->id => 1]))
        ->toThrow(SuggestionConflictException::class, 'A decision cannot accept overlapping edits together.')
        ->and(acceptedReviewText($this->docs, $this->node))->toBe('🦊 red blue');
});

it('refuses a new suggestion once the document holds the most open ones, but still amends and counts only open ones', function (): void {
    $draft = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'rose'));
    $table = app(DatabaseOptions::class)->table('document_suggestions');
    $row = (array) DB::table($table)->where('id', $draft->id)->first();
    $copies = [];
    for ($i = 1; $i < SuggestionOperations::MAX_OPEN_PER_DOCUMENT; $i++) {
        $copies[] = ['id' => (string) Str::uuid7(), 'group_id' => (string) Str::uuid7()] + $row;
    }
    foreach (array_chunk($copies, 100) as $chunk) {
        DB::table($table)->insert($chunk);
    }

    $refused = null;
    try {
        $this->first->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'gold'));
    } catch (UnsupportedSuggestionException $exception) {
        $refused = $exception;
    }
    expect($refused?->problemDetails())->toBe(['reason' => 'open_suggestion_limit']);

    $amended = reviewDraft(['id' => $draft->id, 'groupId' => $draft->groupId, 'after' => 'ruby']);
    expect($this->first->save($this->node->id, $amended, $draft->version)->after)->toBe('ruby');

    DB::table($table)->where('id', $copies[0]['id'])->update(['state' => 'rejected', 'decided_at' => now()]);
    expect($this->first->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'gold'))->state)->toBe('open');
});

it('keeps reserved slots available to pending imports when saving a new draft', function (): void {
    $reservations = new class implements PendingSuggestionReservations
    {
        public int $slots = SuggestionOperations::MAX_OPEN_PER_DOCUMENT;

        public function count(string $documentId, int $generation): int
        {
            return $this->slots;
        }
    };
    app()->instance(PendingSuggestionReservations::class, $reservations);
    $review = app(ReviewManager::class)->suggestions($this->owner);
    $draft = reviewTextEdit(3, 6, 'red', 'rose');
    expect(fn () => $review->save($this->node->id, $draft))->toThrow(UnsupportedSuggestionException::class);
    $reservations->slots--;
    expect($review->save($this->node->id, $draft)->state)->toBe('open');
});

it('pages the visible suggestion history and filters open changes before limiting the page', function (): void {
    $closed = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'green'));
    $this->first->reject($this->node->id, $closed->id, 0, $closed->version);
    $open = $this->second->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'gold'));
    $options = new ListOptions(perPage: 1);
    $first = $this->first->page($this->node->id, $options);
    $second = $this->first->page($this->node->id, $options->cursor($first->nextCursor));
    $active = $this->first->page($this->node->id, $options, openOnly: true);
    expect([$first->items[0]->id, $second->items[0]->id])->toEqualCanonicalizing([$closed->id, $open->id])
        ->and($second->nextCursor)->toBeNull()
        ->and($active->items[0]->id)->toBe($open->id)
        ->and($active->nextCursor)->toBeNull();
});

it('exposes paged suggestions over HTTP while preserving the original list envelope', function (): void {
    $closed = $this->first->save($this->node->id, reviewTextEdit(3, 6, 'red', 'green'));
    $this->first->reject($this->node->id, $closed->id, 0, $closed->version);
    $open = $this->second->save($this->node->id, reviewTextEdit(7, 11, 'blue', 'gold'));
    $url = 'api/vaulter/v1/review/documents/'.$this->node->id.'/suggestions';
    $this->actingAs(AccessTestHelpers::user(1));
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('meta');
    $this->getJson($url.'?open_only=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id)
        ->assertJsonPath('meta.next_cursor', null);
    $this->getJson($url.'?open_only=false')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.next_cursor', null);
    $cursor = $this->getJson($url.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->json('meta.next_cursor');
    $this->getJson($url.'?cursor='.strtoupper($cursor))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    $this->getJson($url.'?cursor=invalid')->assertUnprocessable();
    $this->getJson($url.'?open_only=invalid')->assertUnprocessable();
});
