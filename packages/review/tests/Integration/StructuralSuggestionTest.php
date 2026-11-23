<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
use Vaulter\EditorPro\Review\Data\TextSuggestionInput;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException;
use Vaulter\EditorPro\Review\ReviewManager;

function makeStructuralRichDoc(): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['blockId' => 'h_1', 'level' => 1],
                'content' => [
                    ['type' => 'text', 'text' => 'Title'],
                ],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p_1'],
                'content' => [
                    ['type' => 'text', 'text' => 'Alpha Beta'],
                ],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p_2'],
                'content' => [
                    ['type' => 'text', 'text' => 'Gamma'],
                ],
            ],
            [
                'type' => 'bulletList',
                'content' => [
                    [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'attrs' => ['blockId' => 'l_1'],
                                'content' => [
                                    ['type' => 'text', 'text' => 'List item'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'table',
                'content' => [
                    [
                        'type' => 'tableRow',
                        'content' => [
                            [
                                'type' => 'tableCell',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'attrs' => ['blockId' => 'c_1'],
                                        'content' => [['type' => 'text', 'text' => 'Cell A1']],
                                    ],
                                ],
                            ],
                            [
                                'type' => 'tableCell',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'attrs' => ['blockId' => 'c_2'],
                                        'content' => [['type' => 'text', 'text' => 'Cell B1']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function docArray($docs, $node): array
{
    $array = $docs->content($node)->content->toArray();
    BlockIdentity::assertComplete($array);
    TipTapContent::fromArray($array, TipTapSchema::richText());

    return $array;
}

it('keeps indexed edits to one table in conflict and treats independent lists separately', function (): void {
    $storage = AccessTestHelpers::storage();
    $user = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($user);
    $docs = app(DocumentsManager::class)->in($drive);
    $content = makeStructuralRichDoc();
    $otherList = $content['content'][3];
    $otherList['content'][0]['content'][0]['attrs']['blockId'] = 'l_2';
    $content['content'][] = $otherList;
    foreach (['B', 'C'] as $label) {
        $row = $content['content'][4]['content'][0];
        foreach ($row['content'] as $index => &$cell) {
            $cell['content'][0]['attrs']['blockId'] = 'row_'.$label.$index;
            $cell['content'][0]['content'][0]['text'] = $label;
        }
        unset($cell);
        $content['content'][4]['content'][] = $row;
    }
    $node = $docs->create('table-and-list-overlap', 'vaulter/rich-text', $content);
    $review = app(ReviewManager::class)->suggestions($drive);
    $delete = static fn (string $block, int $row): StructuralSuggestionInput => StructuralSuggestionInput::tableChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, $block, 'delete_row', ['row_index' => $row],
    );
    $toggle = static fn (string $block): StructuralSuggestionInput => StructuralSuggestionInput::listChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, $block, 'toggle_type',
    );
    $first = $review->save($node->id, $delete('c_1', 0));
    $second = $review->save($node->id, $delete('row_B0', 1));
    expect($second->conflictWithId)->toBe($first->id)
        ->and($review->save($node->id, $toggle('l_1'))->conflicted)->toBeFalse()
        ->and($review->save($node->id, $toggle('l_2'))->conflicted)->toBeFalse();
    $review->accept($node->id, $first->id, 0, $first->version);
    $fresh = $review->find($node->id, $second->id);
    expect($fresh->conflicted)->toBeTrue()
        ->and(fn () => $review->accept($node->id, $fresh->id, 0, $fresh->version))->toThrow(SuggestionConflictException::class)
        ->and(docArray($docs, $node)['content'][4]['content'][1]['content'][0]['content'][0]['attrs']['blockId'])->toBe('row_C0');
});

it('rebases mark ranges in UTF-16 and refreshes dependent conflicts when a blocker moves', function (): void {
    $storage = AccessTestHelpers::storage();
    $owner = AccessTestHelpers::user(1);
    $writer = AccessTestHelpers::user(2);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::WRITER, subjectId: 2);
    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $writerDrive = app(Vaulter::class)->drive($storage)->actingAs($writer);
    $docs = app(DocumentsManager::class)->in($ownerDrive);
    $review = app(ReviewManager::class)->suggestions($ownerDrive);
    $writerReview = app(ReviewManager::class)->suggestions($writerDrive);
    $node = $docs->create('retarget-mark', 'vaulter/rich-text', makeStructuralRichDoc());
    $blocker = $review->save($node->id, StructuralSuggestionInput::markChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, 'p_1', 6, 10, 'italic', 'added',
    ));
    $dependent = $writerReview->save($node->id, StructuralSuggestionInput::markChange(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, 'p_1', 6, 10, 'bold', 'added',
    ));
    expect($dependent->conflictWithId)->toBe($blocker->id);
    $amended = $review->save($node->id, StructuralSuggestionInput::markChange(
        $blocker->id, $blocker->groupId, 0, 1, 2, 'p_2', 0, 5, 'italic', 'added',
    ), $blocker->version);
    expect($amended->conflicted)->toBeFalse();
    $fresh = $writerReview->find($node->id, $dependent->id);
    expect($fresh->conflicted)->toBeFalse()
        ->and($fresh->version)->toBe($dependent->version + 1)
        ->and($fresh->authorSubject)->toBe($dependent->authorSubject);
    $insert = $review->save($node->id, new TextSuggestionInput(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, 'p_1', 0, 0, '', '🦊 ',
    ));
    $review->accept($node->id, $insert->id, 0, $insert->version);
    $fresh = $writerReview->find($node->id, $dependent->id);
    expect([$fresh->from, $fresh->to])->toBe([9, 13]);
    $writerReview->accept($node->id, $fresh->id, 0, $fresh->version);
    $inline = docArray($docs, $node)['content'][1]['content'];
    expect($inline[0]['text'])->toBe('🦊 Alpha ')
        ->and($inline[1])->toBe(['type' => 'text', 'text' => 'Beta', 'marks' => [['type' => 'bold']]]);
});

it('preserves textual cell markup and applies link attributes only to the requested range', function (): void {
    $storage = AccessTestHelpers::storage();
    $user = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($user);
    $docs = app(DocumentsManager::class)->in($drive);
    $content = makeStructuralRichDoc();
    $content['content'][2]['content'] = [[
        'type' => 'text', 'text' => 'abcdef', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.example']]],
    ]];
    $node = $docs->create('textual-structure', 'vaulter/rich-text', $content);
    $review = app(ReviewManager::class)->suggestions($drive);
    $change = $review->save($node->id, new StructuralSuggestionInput(
        (string) Str::uuid7(), (string) Str::uuid7(), 0, 1, 2, 'attr_change', 'p_2',
        selector: ['from_utf16' => 2, 'to_utf16' => 4],
        payload: ['node_type' => 'link', 'attr' => 'href', 'old_value' => 'https://old.example', 'new_value' => 'https://new.example'],
    ));
    $review->accept($node->id, $change->id, 0, $change->version);
    $fragments = docArray($docs, $node)['content'][2]['content'];
    expect(array_column($fragments, 'text'))->toBe(['ab', 'cd', 'ef'])
        ->and(array_map(static fn (array $run): string => $run['marks'][0]['attrs']['href'], $fragments))
        ->toBe(['https://old.example', 'https://new.example', 'https://old.example']);

    $text = ['type' => 'text', 'text' => 'kept', 'marks' => [['type' => 'bold']]];
    $paragraph = ['type' => 'paragraph', 'content' => [$text]];
    $tableEdit = static fn (int $position, string $block, string $operation, array $options): StructuralSuggestionInput => StructuralSuggestionInput::tableChange((string) Str::uuid7(), (string) Str::uuid7(), 0, $position, 2, $block, $operation, $options);
    foreach ([
        $tableEdit(2, 'c_1', 'insert_table', ['rows' => 1, 'cols' => 1]),
        $tableEdit(2, 'p_1', 'insert_table', ['rows' => PHP_INT_MAX, 'cols' => PHP_INT_MAX]),
    ] as $unsupported) {
        expect(fn () => $review->save($node->id, $unsupported))->toThrow(UnsupportedSuggestionException::class);
    }
    expect($docs->content($node)->stateVersion)->toBe(2);

    foreach ([
        $tableEdit(2, 'p_1', 'insert_table', ['rows' => 1, 'cols' => 1, 'cells_content' => [$paragraph], 'cell_block_ids' => ['new_cell']]),
        $tableEdit(3, 'new_cell', 'insert_row', ['cells_content' => [[$paragraph]], 'cell_block_ids' => ['new_row']]),
        $tableEdit(4, 'new_cell', 'insert_column', ['cells_content' => [['type' => 'tableCell', 'content' => [$paragraph]]], 'cell_block_ids' => ['new_column']]),
    ] as $input) {
        $saved = $review->save($node->id, $input);
        $review->accept($node->id, $saved->id, 0, $saved->version);
    }
    $table = docArray($docs, $node)['content'][2];
    expect($table['content'][0]['content'][0]['content'][0]['content'])->toBe([$text])
        ->and($table['content'][1]['content'][0]['content'][0]['content'])->toBe([$text])
        ->and($table['content'][0]['content'][1]['content'][0]['content'])->toBe([$text]);
});

it('honors strong suggestion validators and replays snapshot HTTP receipts by key', function (): void {
    $storage = AccessTestHelpers::storage();
    $user = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($user);
    $docs = app(DocumentsManager::class)->in($drive);
    $node = $docs->create('http-suggestion-receipts', 'vaulter/rich-text', makeStructuralRichDoc());
    $this->actingAs($user);
    $url = 'api/vaulter/v1/review/documents/'.$node->id.'/suggestions';
    $payload = [
        'id' => (string) Str::uuid7(), 'group_id' => (string) Str::uuid7(), 'generation' => 0,
        'base_position' => 1, 'schema_version' => 2, 'kind' => 'attr_change', 'target_block_id' => 'h_1',
        'expected_version' => 0, 'payload' => ['node_type' => 'heading', 'attr' => 'level', 'old_value' => 1, 'new_value' => 2],
    ];
    $createKey = ['Idempotency-Key' => 'create-structure'];
    $created = $this->postJson($url, $payload, $createKey)->assertCreated();
    $this->postJson($url, $payload, $createKey)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($created->json());
    $this->postJson($url, array_replace($payload, ['target_block_id' => 'p_1']), $createKey)
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');

    $decision = $url.'/'.$payload['id'].'/accept';
    $body = ['generation' => 0];
    $tag = $created->headers->get('ETag');
    $this->postJson($decision, $body)->assertStatus(428);
    $this->postJson($decision, $body, ['If-Match' => '"stale"'])->assertStatus(412);
    $this->postJson($decision, $body, ['If-Match' => 'W/'.$tag])->assertStatus(412);
    $this->postJson($decision, $body + ['expected_version' => 2], ['If-Match' => $tag])->assertStatus(412);
    $headers = ['If-Match' => $tag, 'Idempotency-Key' => 'accept-structure'];
    $accepted = $this->postJson($decision, $body, $headers)->assertOk();
    $this->postJson($decision, $body, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($accepted->json());
    $this->postJson($decision, $body, ['If-Match' => $tag])->assertStatus(412);
    expect($docs->content($node)->stateVersion)->toBe(2);

    $db = app(VaulterDatabase::class);
    $db->transaction(fn () => $db->connection()->table(app(DatabaseOptions::class)->table('documents'))
        ->where('node_id', $node->id)->update(['schema_version' => 1]));
    $this->getJson($url.'/capabilities')->assertOk()->assertJsonPath('data.structural_kinds', []);
});

it('applies and rejects all 6 structural review profiles in snapshot mode while preserving tree integrity', function (): void {
    $storage = AccessTestHelpers::storage();
    $ownerUser = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($ownerUser);
    $docs = app(DocumentsManager::class)->in($drive);
    $node = $docs->create('structural-doc', 'vaulter/rich-text', makeStructuralRichDoc());
    $suggestions = app(ReviewManager::class)->suggestions($drive);

    // 1. mark_change: add bold on "Alpha" in p_1
    $markSugg = $suggestions->save($node->id, StructuralSuggestionInput::markChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'p_1',
        from: 0,
        to: 5,
        markName: 'bold',
        operation: 'added',
        quoteText: 'Alpha',
    ));
    $acceptedMark = $suggestions->accept($node->id, $markSugg->id, 0, $markSugg->version);
    expect($acceptedMark->state)->toBe('accepted')
        ->and($acceptedMark->receiptId)->not->toBeNull();
    $doc = docArray($docs, $node);
    expect($doc['content'][1]['content'][0]['marks'] ?? [])->toBe([['type' => 'bold']]);

    // 2. attr_change: heading level 1 -> 3
    $attrSugg = $suggestions->save($node->id, StructuralSuggestionInput::attrChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 2,
        schemaVersion: 2,
        targetBlockId: 'h_1',
        nodeType: 'heading',
        attr: 'level',
        oldValue: 1,
        newValue: 3,
    ));
    $suggestions->accept($node->id, $attrSugg->id, 0, $attrSugg->version);
    $doc = docArray($docs, $node);
    expect($doc['content'][0]['attrs']['level'])->toBe(3);

    // 3. block_split (structural): split p_1 ("Alpha Beta") at offset 5 into p_1 ("Alpha") + p_1_split (" Beta")
    $splitSugg = $suggestions->save($node->id, StructuralSuggestionInput::blockSplit(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 3,
        schemaVersion: 2,
        targetBlockId: 'p_1',
        offset: 5,
        splitKind: 'structural',
        newBlockId: 'p_1_split',
        newNodeType: 'paragraph',
    ));
    $suggestions->accept($node->id, $splitSugg->id, 0, $splitSugg->version);
    $doc = docArray($docs, $node);
    expect($doc['content'][1]['content'][0]['text'])->toBe('Alpha')
        ->and($doc['content'][2]['attrs']['blockId'])->toBe('p_1_split')
        ->and($doc['content'][2]['content'][0]['text'])->toBe(' Beta');

    // 4. block_join: join p_1_split (" Beta") with p_2 ("Gamma")
    $joinSugg = $suggestions->save($node->id, StructuralSuggestionInput::blockJoin(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 4,
        schemaVersion: 2,
        targetBlockId: 'p_1_split',
        secondBlockId: 'p_2',
        joinOffsetUtf16: 5,
    ));
    $suggestions->accept($node->id, $joinSugg->id, 0, $joinSugg->version);
    $doc = docArray($docs, $node);
    expect($doc['content'][2]['attrs']['blockId'])->toBe('p_1_split')
        ->and($doc['content'][2]['content'][0]['text'])->toBe(' BetaGamma');

    // 5. list_change: toggle bulletList (containing l_1) -> orderedList
    $listSugg = $suggestions->save($node->id, StructuralSuggestionInput::listChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 5,
        schemaVersion: 2,
        targetBlockId: 'l_1',
        listOp: 'toggle_type',
        listAttrs: ['start' => 1],
    ));
    $suggestions->accept($node->id, $listSugg->id, 0, $listSugg->version);
    $doc = docArray($docs, $node);
    expect($doc['content'][3]['type'])->toBe('orderedList');

    // 6. table_change: toggle header row on table anchored at c_1, then insert row and reject one row insertion
    $tableHeaderSugg = $suggestions->save($node->id, StructuralSuggestionInput::tableChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 6,
        schemaVersion: 2,
        targetBlockId: 'c_1',
        tableOp: 'toggle_header_row',
        options: ['row_index' => 0],
    ));
    $suggestions->accept($node->id, $tableHeaderSugg->id, 0, $tableHeaderSugg->version);
    $doc = docArray($docs, $node);
    expect($doc['content'][4]['content'][0]['content'][0]['type'])->toBe('tableHeader');

    $rejectTableSugg = $suggestions->save($node->id, StructuralSuggestionInput::tableChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 7,
        schemaVersion: 2,
        targetBlockId: 'c_1',
        tableOp: 'insert_row',
        options: ['row_index' => 1, 'cell_block_ids' => ['c_row2_a', 'c_row2_b']],
    ));
    $rejected = $suggestions->reject($node->id, $rejectTableSugg->id, 0, $rejectTableSugg->version);
    expect($rejected->state)->toBe('rejected')
        ->and(docArray($docs, $node)['content'][4]['content'])->toHaveCount(1);
});

it('rejects unsupported structures before mutation and exposes HTTP capabilities & conflict responses', function (): void {
    $storage = AccessTestHelpers::storage();
    $ownerUser = AccessTestHelpers::user(1);
    $writerUser = AccessTestHelpers::user(2);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::WRITER, subjectId: 2);

    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($ownerUser);
    $docs = app(DocumentsManager::class)->in($ownerDrive);
    $node = $docs->create('guardrail-doc', 'vaulter/rich-text', makeStructuralRichDoc());

    // HTTP Capabilities endpoint (v1 hides node_type_change_types and enforces text_only tables)
    $this->actingAs($ownerUser);
    $capsRes = $this->getJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions/capabilities')
        ->assertOk()
        ->json('data');
    expect($capsRes['node_type_change_types'])->toBe([])
        ->and($capsRes['table_mode'])->toBe('text_only')
        ->and($capsRes['structural_kinds'])->toContain('mark_change', 'attr_change', 'block_split', 'block_join', 'list_change', 'table_change');

    // Pre-write rejection 1: unsupported kind (`node_type_change` disabled in v1)
    $this->withHeaders(['Idempotency-Key' => 'unsup-node-type'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions', [
            'id' => (string) Str::uuid7(),
            'group_id' => (string) Str::uuid7(),
            'generation' => 0,
            'base_position' => 1,
            'schema_version' => 2,
            'kind' => 'node_type_change',
            'target_block_id' => 'p_1',
            'payload' => ['from_type' => 'paragraph', 'to_type' => 'heading'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unsupported_suggestion');

    // Pre-write rejection 2: immutable blockId attr_change
    $this->withHeaders(['Idempotency-Key' => 'unsup-block-id'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions', [
            'id' => (string) Str::uuid7(),
            'group_id' => (string) Str::uuid7(),
            'generation' => 0,
            'base_position' => 1,
            'schema_version' => 2,
            'kind' => 'attr_change',
            'target_block_id' => 'h_1',
            'payload' => ['node_type' => 'heading', 'attr' => 'blockId', 'old_value' => 'h_1', 'new_value' => 'h_hacked'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unsupported_suggestion');

    // Pre-write rejection 3: disallowed mark & unsafe javascript: href
    $reviewMgr = app(ReviewManager::class)->suggestions($ownerDrive);
    expect(fn () => $reviewMgr->save($node->id, StructuralSuggestionInput::markChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'p_1',
        from: 0,
        to: 5,
        markName: 'highlight',
        operation: 'added',
    )))->toThrow(UnsupportedSuggestionException::class);

    expect(fn () => $reviewMgr->save($node->id, StructuralSuggestionInput::markChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'p_1',
        from: 0,
        to: 5,
        markName: 'link',
        operation: 'added',
        markAttrs: ['href' => 'javascript:alert(1)'],
    )))->toThrow(UnsupportedSuggestionException::class);

    // Pre-write rejection 4: non-textual table cell content rejected without mutation
    $mediaDoc = makeStructuralRichDoc();
    $mediaDoc['content'][4]['content'][0]['content'][0]['content'][] = [
        'type' => 'codeBlock',
        'attrs' => ['language' => 'php'],
        'content' => [['type' => 'text', 'text' => 'echo 1;']],
    ];
    $mediaNode = $docs->create('non-textual-table-doc', 'vaulter/rich-text', $mediaDoc);
    $beforeContent = $docs->content($mediaNode)->content->toArray();

    $nonTextualTableSugg = StructuralSuggestionInput::tableChange(
        id: (string) Str::uuid7(),
        groupId: (string) Str::uuid7(),
        generation: 0,
        basePosition: 1,
        schemaVersion: 2,
        targetBlockId: 'c_1',
        tableOp: 'insert_row',
        options: ['row_index' => 1, 'cell_block_ids' => ['new_c1', 'new_c2']],
    );
    expect(fn () => $reviewMgr->save($mediaNode->id, $nonTextualTableSugg))
        ->toThrow(UnsupportedSuggestionException::class);
    expect($reviewMgr->list($mediaNode->id))->toBe([])
        ->and($docs->content($mediaNode)->content->toArray())->toBe($beforeContent);

    // Overlap conflict policy v1 via HTTP:
    // User 1 creates structural block_split on p_1; User 2 creates overlapping text edit after split offset.
    $s1Id = (string) Str::uuid7();
    $g1Id = (string) Str::uuid7();
    $this->actingAs($ownerUser);
    $this->withHeaders(['Idempotency-Key' => 'http-s1'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions', [
            'id' => $s1Id,
            'group_id' => $g1Id,
            'generation' => 0,
            'base_position' => 1,
            'schema_version' => 2,
            'kind' => 'block_split',
            'target_block_id' => 'p_1',
            'from' => 5,
            'to' => 5,
            'payload' => [
                'split_kind' => 'structural',
                'new_block_id' => 'p_split_http',
                'offset_utf16' => 5,
            ],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.conflicted', false);

    $this->actingAs($writerUser);
    $s2Id = (string) Str::uuid7();
    $s2Res = $this->withHeaders(['Idempotency-Key' => 'http-s2'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions', [
            'id' => $s2Id,
            'group_id' => (string) Str::uuid7(),
            'generation' => 0,
            'base_position' => 1,
            'schema_version' => 2,
            'kind' => 'replace',
            'target_block_id' => 'p_1',
            'from' => 6,
            'to' => 10,
            'before' => 'Beta',
            'after' => 'Delta',
        ])
        ->assertStatus(201)
        ->json('data');

    expect($s2Res['state'])->toBe('open')
        ->and($s2Res['conflicted'])->toBeTrue()
        ->and($s2Res['conflict_with_id'])->toBe($s1Id)
        ->and((string) $s2Res['author_subject'])->toEndWith(':2');

    // Conflicted suggestion cannot be accepted via HTTP (409) and does not mutate document
    $this->withHeaders(['Idempotency-Key' => 'http-s2-accept-fail'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions/'.$s2Id.'/accept', [
            'generation' => 0,
            'expected_version' => $s2Res['version'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'suggestion_conflict');

    // Rejecting S1 clears conflict on S2, preserving S2 original author_subject
    $this->actingAs($ownerUser);
    $this->withHeaders(['Idempotency-Key' => 'http-s1-reject'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions/'.$s1Id.'/reject', [
            'generation' => 0,
            'expected_version' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected');

    $afterList = array_column(
        $this->getJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions')->assertOk()->json('data'),
        null,
        'id',
    );
    expect($afterList[$s2Id]['conflicted'])->toBeFalse()
        ->and($afterList[$s2Id]['conflict_with_id'])->toBeNull()
        ->and((string) $afterList[$s2Id]['author_subject'])->toEndWith(':2');

    // Now Writer accepts S2 cleanly via group accept
    $this->actingAs($writerUser);
    $this->withHeaders(['Idempotency-Key' => 'http-s2-group-accept'])
        ->postJson('api/vaulter/v1/review/documents/'.$node->id.'/suggestions/groups/'.$s2Res['group_id'].'/accept', [
            'generation' => 0,
            'expected_versions' => [$s2Id => $afterList[$s2Id]['version']],
        ])
        ->assertOk();

    $updatedPara = docArray($docs, $node)['content'][1]['content'] ?? [];
    expect(implode('', array_column($updatedPara, 'text')))->toBe('Alpha Delta');
    expect($reviewMgr->list($node->id))->toHaveCount(2);
});
