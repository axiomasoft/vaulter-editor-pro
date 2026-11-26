<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange;

use Packman\Attributes\Api;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Jobs\RunDocxExchangeJob;
use Vaulter\EditorPro\Exchange\Operations\ExchangeOperations;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;

/**
 * Public entrypoint for Vaulter Editor Pro DOCX Exchange operations on a drive:
 * `ExchangeManager::forDrive($drive)`.
 */
#[Api(since: '1.0')]
final readonly class ExchangeManager
{
    public function __construct(
        private ExchangeJobRepository $jobs,
        private ExchangeOptions $options,
    ) {}

    public function forDrive(DriveHandle $drive): ExchangeOperations
    {
        return $drive->extension(ExchangeOperations::class);
    }

    /**
     * Reclaim expired leases. With automatic dispatch, redeliver reclaimed and stale queued jobs.
     *
     * @return list<string> reclaimed job IDs
     */
    public function reclaimExpiredLeases(int $limit = 100): array
    {
        return $this->jobs->reclaimExpiredLeases(
            $limit,
            $this->options->leaseSeconds,
            $this->options->autoDispatch
                ? fn (string $id, string $tenant, string $scope, string $subject) => RunDocxExchangeJob::enqueue($id, $tenant, $scope, $subject, $this->options)
                : null,
        );
    }

    /**
     * Purge expired terminal/stale exchange jobs and unpin their retained Media blobs.
     */
    public function purgeExpiredJobs(?int $retentionSeconds = null, int $limit = 100): int
    {
        return $this->jobs->purgeExpired(
            $retentionSeconds ?? $this->options->jobRetentionSeconds,
            $limit,
        );
    }
}
