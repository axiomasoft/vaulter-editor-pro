<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Resolves an acting user for queued background exchange execution. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExchangeActorResolver
{
    public function resolve(string $tenantId, string $projectScope, string $effectiveSubject): ?Authorizable;
}
