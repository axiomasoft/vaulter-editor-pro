<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Counts slots reserved for work admitted under the same document lock as a new draft. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PendingSuggestionReservations
{
    public function count(string $documentId, int $generation): int;
}
