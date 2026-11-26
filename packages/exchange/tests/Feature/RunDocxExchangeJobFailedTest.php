<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeExportResult;
use Vaulter\EditorPro\Exchange\Data\ExchangeRuntimeImportResult;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\ExchangeManager;
use Vaulter\EditorPro\Exchange\Jobs\RunDocxExchangeJob;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\EditorPro\Exchange\Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** A converter that is down: every call fails with the transient runtime error. */
final class UnavailableDocxRuntime implements DocxExchangeRuntime
{
    public function importDocx(string $docxBytes, string $profileId, ?array $sourceMarker = null, array $options = []): ExchangeRuntimeImportResult
    {
        throw ExchangeException::runtimeFailed('process_failed', 'converter unavailable');
    }

    public function exportDocx(
        array $document,
        string $profileId,
        ?array $sourceMarker = null,
        array $discussions = [],
        array $options = [],
        array $sections = [],
        array $revisions = [],
    ): ExchangeRuntimeExportResult {
        throw ExchangeException::runtimeFailed('process_failed', 'converter unavailable');
    }
}

/** Registers an export job for a fresh document and returns its ID and the queued worker that would run it. */
function registerExportJob(TestCase $test, string $requestKey): RunDocxExchangeJob
{
    $owner = AccessTestHelpers::user(1);
    $storage = AccessTestHelpers::storage();
    AccessTestHelpers::grant($storage->rootNode, RoleRegistry::OWNER, subjectId: 1);
    $drive = app(Vaulter::class)->drive($storage)->actingAs($owner);
    $docNode = app(DocumentsManager::class)->in($drive)->create('export-target', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'attrs' => ['blockId' => 'blk_p1'], 'content' => [['type' => 'text', 'text' => 'Body']]]],
    ]);

    $test->actingAs($owner)->withHeaders(['Idempotency-Key' => $requestKey])
        ->postJson('api/vaulter/v1/documents/'.$docNode->id.'/exchange-jobs', [
            'request_key' => $requestKey,
            'kind' => 'export',
            'profile_id' => 'standard',
        ]);

    $row = DB::table(app(DatabaseOptions::class)->table('document_exchange_jobs'))->where('request_key', $requestKey)->first();
    expect($row)->not->toBeNull();

    return new RunDocxExchangeJob(
        (string) $row->id,
        $drive->snapshot()->id,
        (string) $row->tenant_id,
        (string) $row->project_scope,
        (string) $row->effective_subject,
    );
}

it('fails the job once the queue gives up on a transient runtime error instead of leaving it queued', function (): void {
    app()->instance(DocxExchangeRuntime::class, new UnavailableDocxRuntime);

    // With the sync driver the queue gives up after the first attempt, which is exactly the "no more retries" case.
    $worker = registerExportJob($this, 'export-runtime-down');
    $job = app(ExchangeJobRepository::class)->findById($worker->jobId);

    expect($job?->state)->toBe(ExchangeJobState::Failed)
        ->and($job?->errorCode)->toBe('exchange_runtime_failed')
        ->and($job?->diagnosticsReport)->toBe(['reason' => 'process_failed']);
});

it('does not store the message of an unexpected worker exception', function (): void {
    config()->set('vaulter-editor-pro-exchange.auto_dispatch', false);
    $worker = registerExportJob($this, 'export-unexpected');

    $worker->failed(new RuntimeException('SQLSTATE[08006] at /srv/app/secret.php'));

    $job = app(ExchangeJobRepository::class)->findById($worker->jobId);
    expect($job?->state)->toBe(ExchangeJobState::Failed)
        ->and($job?->errorCode)->toBe('exchange_runtime_failed')
        ->and($job?->diagnosticsReport)->toBe(['reason' => 'worker_failed']);
});

it('leaves a job alone while a live worker still holds its lease', function (): void {
    config()->set('vaulter-editor-pro-exchange.auto_dispatch', false);
    $worker = registerExportJob($this, 'export-leased');
    $repo = app(ExchangeJobRepository::class);
    expect($repo->claimLease($worker->jobId, 300))->toBeString();

    $worker->failed(new RuntimeException('timed out'));

    expect($repo->findById($worker->jobId)?->state)->toBe(ExchangeJobState::Running);
});

it('redelivers expired and unpublished work to the original queue with its original actor', function (): void {
    Queue::fake();
    config()->set('vaulter-editor-pro-exchange.queue_name', 'docx-test');
    $worker = registerExportJob($this, 'recover-after-timeout');
    $jobs = app(ExchangeJobRepository::class);
    expect($jobs->claimLease($worker->jobId, 60))->toBeString();
    $worker->failed(new RuntimeException('queue attempts exhausted'));
    $manager = app(ExchangeManager::class);
    expect($manager->reclaimExpiredLeases())->toBe([]);
    Queue::assertPushed(RunDocxExchangeJob::class, 1);

    $this->travel(2)->minutes();
    expect($manager->reclaimExpiredLeases())->toBe([$worker->jobId])
        ->and($jobs->findById($worker->jobId)?->state)->toBe(ExchangeJobState::Queued);
    Queue::assertPushed(RunDocxExchangeJob::class, 2);
    foreach (Queue::pushed(RunDocxExchangeJob::class) as $delivery) {
        expect($delivery->driveId)->toBe($worker->driveId)
            ->and($delivery->tenantId)->toBe($worker->tenantId)
            ->and($delivery->projectScope)->toBe($worker->projectScope)
            ->and($delivery->effectiveSubject)->toBe($worker->effectiveSubject)
            ->and($delivery->queue)->toBe('docx-test');
    }

    // Losing the broker message after commit leaves a durable queued intent for the next maintenance run.
    expect($manager->reclaimExpiredLeases())->toBe([]);
    $this->travel(2)->minutes();
    expect($manager->reclaimExpiredLeases())->toBe([$worker->jobId]);
    Queue::assertPushed(RunDocxExchangeJob::class, 3);
});

it('keeps a later attempt queued when a fenced worker exhausts its own retries', function (): void {
    config()->set('vaulter-editor-pro-exchange.auto_dispatch', false);
    $worker = registerExportJob($this, 'another-worker-retry');
    $worker->failed(ExchangeException::leaseLost());
    expect(app(ExchangeJobRepository::class)->findById($worker->jobId)?->state)->toBe(ExchangeJobState::Queued);
});
