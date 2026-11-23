<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Operations;

use Packman\Attributes\Internal;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/** Mutating operation policies declared by `exchange` module. */
#[Internal]
final class ExchangeOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'exchange.jobs.register' => new OperationPolicy('exchange.job', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['exchange.jobs.store']),
            'exchange.jobs.apply' => new OperationPolicy('exchange.job', ConcurrencyPolicy::Natural, IdempotencyPolicy::Receipt, ['exchange.jobs.apply']),
        ];
    }
}
