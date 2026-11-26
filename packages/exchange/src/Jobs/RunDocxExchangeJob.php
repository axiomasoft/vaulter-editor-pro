<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
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

    public static function enqueue(
        string $jobId,
        string $tenantId,
        string $projectScope,
        string $effectiveSubject,
        ExchangeOptions $options,
    ): void {
        // ExchangeLookup stores the registering scope as "<kind>:<id>".
        $job = new self($jobId, Str::after($projectScope, ':'), $tenantId, $projectScope, $effectiveSubject);
        dispatch($job->onConnection($options->queueConnection)->onQueue($options->queueName));
    }

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
     * this callback records the terminal outcome when no other worker owns the lease. The recovery command
     * redelivers an expired lease if the queue exhausts its attempts while the lease is still live.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof ExchangeException && $exception->problemCode() === ExchangeErrorCode::LeaseLost->problemSlug()) {
            return;
        }
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
