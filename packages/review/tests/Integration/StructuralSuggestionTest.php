<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\StructuralSuggestionInput;
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
