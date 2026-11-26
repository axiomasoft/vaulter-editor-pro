<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\ExchangeManager;

/** Reclaims expired DOCX exchange leases and purges expired jobs + unpins retained Media blobs. */
#[Internal]
final class RecoverExchangeJobsCommand extends Command
{
    protected $signature = 'vaulter-editor-pro-exchange:recover
        {--limit=100 : Maximum jobs processed per phase}';

    protected $description = 'Reclaim stale DOCX exchange leases and purge expired exchange jobs + pins';

    public function handle(ExchangeManager $exchange): int
    {
        $rawLimit = $this->option('limit');
        $limit = is_numeric($rawLimit) && (int) $rawLimit > 0 ? (int) $rawLimit : 100;

        $reclaimed = $exchange->reclaimExpiredLeases($limit);
        $purged = $exchange->purgeExpiredJobs(limit: $limit);

        $this->components->info(sprintf(
            'Recovered %d exchange job(s), purged %d expired exchange job(s).',
            count($reclaimed),
            $purged,
        ));

        return self::SUCCESS;
    }
}
