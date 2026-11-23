<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ExchangeJobState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case PreviewReady = 'preview_ready';
    case Completed = 'completed';
    case Applied = 'applied';
    case Failed = 'failed';
}
