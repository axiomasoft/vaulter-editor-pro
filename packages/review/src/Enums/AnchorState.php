<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Api;

/** Lifecycle state of a generation-scoped comment anchor. */
#[Api(since: '1.0')]
enum AnchorState: string
{
    case Attached = 'attached';
    case Detached = 'detached';
    case Historical = 'historical';
}
