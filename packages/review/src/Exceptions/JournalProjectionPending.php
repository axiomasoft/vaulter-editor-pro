<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Exceptions;

use Packman\Attributes\Internal;
use RuntimeException;
use Vaulter\Documents\State\PendingMarker;

/** Turn this retryable refusal into an HTTP problem after the idempotency claim rolls back. */
#[Internal]
final class JournalProjectionPending extends RuntimeException
{
    public function __construct(public readonly ?PendingMarker $marker)
    {
        parent::__construct('The journal head is not projected.');
    }
}
