<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Contracts\ExchangeActorResolver;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Exchange\Errors\ExchangeErrorCode;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\Operations\ExchangeOperations;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;

/**
 * Queued worker executing one DOCX exchange job (`v_document_exchange_jobs`) under lease fencing.
 * Stores only scalar IDs (never serialized Eloquent/Actor models) and re-authorizes ACL on execution.
 */
#[Internal]
final class RunDocxExchangeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Long enough to write one terminal state; a real worker lease is never this short-lived. */
    private const int TERMINAL_WRITE_LEASE_SECONDS = 10;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $jobId,
        public readonly string $driveId,
        public readonly string $tenantId,
        public readonly string $projectScope,
        public readonly string $effectiveSubject,
    ) {}

    public function uniqueId(): string
    {
        return $this->jobId;
    }

    public function handle(
        Vaulter $vaulter,
        ExchangeActorResolver $actorResolver,
        ExchangeJobRepository $jobs,
    ): void {
        $meta = $jobs->findRawMetadata($this->jobId);
        if ($meta === null) {
            return;
        }

        $state = $meta['state'];
        if ($state !== ExchangeJobState::Queued->value && $state !== ExchangeJobState::Running->value) {
            return;
        }

        $drive = $vaulter->findDriveById($this->driveId);
        if ($drive === null) {
            return;
        }

        if (str_starts_with($this->effectiveSubject, 'system:')) {
            $reason = substr($this->effectiveSubject, 7);
            $drive = $drive->asSystem($reason !== '' ? $reason : 'exchange-worker');
        } else {
            $user = $actorResolver->resolve($this->tenantId, $this->projectScope, $this->effectiveSubject);
            if ($user === null) {
                $token = $jobs->claimLease($this->jobId, self::TERMINAL_WRITE_LEASE_SECONDS);
                if ($token !== null) {
                    $jobs->markFailed($this->jobId, $token, 'actor_unavailable', ['reason' => 'actor_unavailable']);
                }

                return;
            }
            $drive = $drive->actingAs($user);
        }

        $drive->extension(ExchangeOperations::class)->process($this->jobId);
    }

    /**
     * Called by the queue once it gives up on this job (attempts exhausted, timeout, or a sync-driver failure).
     *
     * ExchangeJobProcessor hands transient failures back to `queued` and rethrows so the queue can retry, and
     * nothing dispatches a row again after the queue stops. Without this the row would stay `queued` forever and
     * clients would poll it indefinitely, so the job is failed with the last error instead. A row that is already
     * terminal, or whose lease a live worker still holds, is left alone.
     */
    public function failed(?Throwable $exception): void
    {
        $jobs = Container::getInstance()->make(ExchangeJobRepository::class);
        $token = $jobs->claimLease($this->jobId, self::TERMINAL_WRITE_LEASE_SECONDS);
        if ($token === null) {
            return;
        }

        if ($exception instanceof ExchangeException) {
            $jobs->markFailed($this->jobId, $token, $exception->problemCode(), $exception->problemDetails());

            return;
        }

        // Unexpected exceptions may carry paths or SQL in their message, which must not reach the stored report.
        $jobs->markFailed($this->jobId, $token, ExchangeErrorCode::RuntimeFailed->problemSlug(), ['reason' => 'worker_failed']);
    }
}
