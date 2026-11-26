<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Repositories;

use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Enums\ExchangeJobState;
use Vaulter\EditorPro\Review\Contracts\PendingSuggestionReservations;
use Vaulter\Support\Identity;

/** Room commands retain capacity until rejection or until their committed suggestions are materialized. */
#[Internal]
final readonly class JournalImportReservations implements PendingSuggestionReservations
{
    public function __construct(private ExchangeLookup $lookup) {}

    public function count(string $documentId, int $generation): int
    {
        $connection = $this->lookup->connection();
        $jobs = $this->lookup->table('document_exchange_jobs');
        // Locking reads observe admissions serialized by the document lock even under InnoDB repeatable read.
        $slots = $connection->table($jobs)->where('document_id', $documentId)->where('source_generation', $generation)
            ->where('state', ExchangeJobState::PreviewReady->value)->where('reserved_suggestions', '>', 0)
            ->lockForUpdate()->pluck('reserved_suggestions', 'reservation_command_id');
        if ($slots->isEmpty()) {
            return 0;
        }

        $commands = $connection->table($this->lookup->table('collaboration_commands'))
            ->whereIn('id', $slots->keys())->whereIn('state', ['running', 'committed'])
            ->lockForUpdate()->pluck('id');

        $total = 0;
        foreach ($commands as $commandId) {
            $total += (int) Identity::stringify($slots->get(Identity::stringify($commandId)));
        }

        return $total;
    }
}
