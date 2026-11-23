<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Jobs;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\ValueObjects\Operations\ScopeRef;

/**
 * Releases Media blob pins (`editor_pro.exchange_source` and `editor_pro.exchange_output`)
 * for exchange jobs cascade-deleted when a parent document node is permanently purged.
 */
#[Internal]
final readonly class ReleaseExchangePinsOnPurge implements OperationMiddleware
{
    private const string PURGE = 'drive.trash.purge';

    public function __construct(private ExchangeJobRepository $jobs) {}

    public function handle(Operation $operation, Closure $next): mixed
    {
        $scope = $operation->context->scope;
        if ($operation->spec->name !== self::PURGE || $operation->isNested() || $scope->kind !== ScopeRef::DRIVE) {
            return $next($operation);
        }

        $projectScope = $scope->kind.':'.$scope->id;
        $candidates = $this->jobs->findJobsForProjectScope($projectScope);

        $result = $next($operation);

        if ($candidates !== []) {
            $this->jobs->releasePinsForDeletedJobs($candidates);
        }

        return $result;
    }
}
