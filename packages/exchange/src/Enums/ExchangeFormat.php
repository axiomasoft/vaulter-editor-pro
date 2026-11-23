<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ExchangeFormat: string
{
    case Docx = 'docx';
}
