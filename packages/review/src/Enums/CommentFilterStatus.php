<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Enums;

use Packman\Attributes\Api;

/** Filter bucket for listing anchored comments in the review sidebar and API. */
#[Api(since: '1.0')]
enum CommentFilterStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Resolved = 'resolved';
    case Detached = 'detached';
    case Archived = 'archived';
}
