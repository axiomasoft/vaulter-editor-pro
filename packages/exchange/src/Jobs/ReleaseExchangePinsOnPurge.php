<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Jobs;

use Packman\Attributes\Internal;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/**
 * Releases Media blob pins (`editor_pro.exchange_source` and `editor_pro.exchange_output`)
 * for exchange jobs cascade-deleted when a parent document node is permanently purged.
 */
#[Internal]
final readonly class ReleaseExchangePinsOnPurge implements EventSink
{
    public function __construct(private ExchangeJobRepository $jobs) {}

    public function record(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        if ($event instanceof NodePurged) {
            $this->jobs->releasePinsForDeletedJobs($writer->connection());
        }

        return SinkResult::Skipped;
    }
}
