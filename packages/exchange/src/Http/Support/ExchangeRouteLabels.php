<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Http\Support;

use Packman\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;

/** Metric labels and route path patterns for `vaulter-editor-pro-exchange` endpoints. */
#[Internal]
final class ExchangeRouteLabels implements RouteLabelSource
{
    public function operations(): array
    {
        return [
            'vaulter.v1.exchange.jobs.store' => 'doc_write',
            'vaulter.v1.exchange.jobs.show' => 'doc_read',
            'vaulter.v1.exchange.jobs.file' => 'doc_read',
            'vaulter.v1.exchange.jobs.apply' => 'doc_write',
        ];
    }

    public function operationPaths(): array
    {
        return [
            ['methods' => ['POST'], 'pattern' => 'v1/documents/{id}/exchange-jobs', 'operation' => 'doc_write'],
            ['methods' => ['GET'], 'pattern' => 'v1/documents/{id}/exchange-jobs/{id}', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'v1/documents/{id}/exchange-jobs/{id}/file', 'operation' => 'doc_read'],
            ['methods' => ['POST'], 'pattern' => 'v1/documents/{id}/exchange-jobs/{id}/apply', 'operation' => 'doc_write'],
        ];
    }

    public function throttled(): array
    {
        return [];
    }
}
