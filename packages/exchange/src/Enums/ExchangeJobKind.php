<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ExchangeJobKind: string
{
    case Import = 'import';
    case Export = 'export';
}
