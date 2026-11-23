<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Packman\Attributes\Internal;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Exchange\Contracts\ExchangeActorResolver;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
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
                $token = $jobs->claimLease($this->jobId, 10);
                if ($token !== null) {
                    $jobs->markFailed($this->jobId, $token, 'actor_unavailable', ['reason' => 'actor_unavailable']);
                }

                return;
            }
            $drive = $drive->actingAs($user);
        }

        $drive->extension(ExchangeOperations::class)->process($this->jobId);
    }
}
